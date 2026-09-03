<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Notifications\EquipmentStatusChangedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 1 equipment tracking: a serial-number keyed inventory of units, the
 * lease each one is currently on, and the simple tracking fields (condition,
 * delivery date, expected return/ownership date).
 *
 * Every route here sits behind permission:equipment_tracking — see routes/api.php.
 *
 * Phase 2 (live GPS provider) is deliberately out of scope: gps_device_id and
 * the gps_events table already exist so a provider can be wired in later
 * without reshaping this controller.
 */
class EquipmentUnitController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(EquipmentUnit::STATUSES)],
            'search' => ['nullable', 'string', 'max:255'],
            'assignable' => ['nullable', 'boolean'],
        ]);

        $units = EquipmentUnit::query()
            ->with(['currentLease.customer:id,name,email', 'updatedBy:id,name'])
            ->withCount('serviceRecords')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->search($search))
            ->when($request->boolean('assignable'), fn ($q) => $q->whereIn('status', EquipmentUnit::ASSIGNABLE_STATUSES))
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $units->map(fn (EquipmentUnit $unit) => $this->present($unit)),
            'meta' => ['counts' => $this->statusCounts()],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $unit = EquipmentUnit::create($data);

        return response()->json(['data' => $this->present($unit->fresh())], 201);
    }

    public function show(EquipmentUnit $equipmentUnit)
    {
        $equipmentUnit->load([
            'currentLease.customer:id,name,email',
            'serviceRecords.performedBy:id,name',
            'updatedBy:id,name',
        ]);

        return response()->json(['data' => $this->present($equipmentUnit, includeHistory: true)]);
    }

    public function update(Request $request, EquipmentUnit $equipmentUnit)
    {
        $data = $request->validate($this->rules($equipmentUnit));

        // Status is only editable here while the unit is off-lease. Moving a unit
        // on and off a lease goes through assign()/release() so the lease row and
        // the unit row can never disagree about who is holding it.
        if (array_key_exists('status', $data)
            && $data['status'] !== $equipmentUnit->status
            && $equipmentUnit->currentLease()->exists()) {
            abort(422, 'This unit is attached to a lease. Use assign or release to change its status.');
        }

        $equipmentUnit->update(array_merge($data, ['updated_by' => Auth::id()]));

        $equipmentUnit->load(['currentLease.customer:id,name,email', 'serviceRecords.performedBy:id,name', 'updatedBy:id,name']);

        return response()->json(['data' => $this->present($equipmentUnit, includeHistory: true)]);
    }

    public function destroy(EquipmentUnit $equipmentUnit)
    {
        if ($equipmentUnit->leaseAgreements()->exists()) {
            abort(422, 'This unit has lease history and cannot be deleted. Mark it returned instead.');
        }

        $equipmentUnit->delete();

        return response()->noContent();
    }

    /**
     * Put a unit on a lease. This is the single point where a serial number
     * becomes tied to a customer: it stamps the lease, flips the unit to
     * leased, and derives the expected ownership date from the lease term.
     */
    public function assign(Request $request, EquipmentUnit $equipmentUnit)
    {
        $data = $request->validate([
            'lease_agreement_id' => ['required', 'integer', 'exists:lease_agreements,id'],
            'delivery_date' => ['nullable', 'date'],
            'condition_notes' => ['nullable', 'string'],
        ]);

        if (! $equipmentUnit->isAssignable()) {
            abort(422, 'This unit is not available — release it from its current lease first.');
        }

        $lease = LeaseAgreement::findOrFail($data['lease_agreement_id']);

        if ($lease->equipment_unit_id && $lease->equipment_unit_id !== $equipmentUnit->id) {
            abort(422, 'That lease already has a unit assigned. Release it first.');
        }

        $deliveryDate = $data['delivery_date'] ?? now()->toDateString();

        $expectedDate = $lease->start_date && $lease->term_months
            ? $lease->start_date->copy()->addMonths((int) $lease->term_months)->toDateString()
            : null;

        DB::transaction(function () use ($lease, $equipmentUnit, $data, $deliveryDate, $expectedDate) {
            $lease->update(['equipment_unit_id' => $equipmentUnit->id]);

            $equipmentUnit->update([
                'status' => EquipmentUnit::STATUS_LEASED,
                'delivery_date' => $deliveryDate,
                'expected_return_or_ownership_date' => $expectedDate,
                'condition_notes' => $data['condition_notes'] ?? $equipmentUnit->condition_notes,
                'updated_by' => Auth::id(),
            ]);
        });

        $equipmentUnit->refresh()->load(['currentLease.customer:id,name,email', 'serviceRecords.performedBy:id,name', 'updatedBy:id,name']);

        // "...emails" is the field name from the customer's own preferences UI, but this
        // app has no email channel wired up yet — the toggle controls the in-app
        // notification instead, since that's the only one that exists.
        if ($lease->customer && ($lease->customer->customerProfile?->status_change_emails ?? true)) {
            $lease->customer->notify(new EquipmentStatusChangedNotification(
                $equipmentUnit,
                "Your {$equipmentUnit->model} is scheduled for delivery on {$deliveryDate}.",
            ));
        }

        return response()->json(['data' => $this->present($equipmentUnit, includeHistory: true)]);
    }

    /**
     * Take a unit back off its lease. owned_by_customer keeps the lease link
     * intact — the customer paid it off, so the history has to stay attached;
     * returned and in_stock detach the unit so the lease no longer claims it.
     */
    public function release(Request $request, EquipmentUnit $equipmentUnit)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                EquipmentUnit::STATUS_RETURNED,
                EquipmentUnit::STATUS_IN_STOCK,
                EquipmentUnit::STATUS_OWNED_BY_CUSTOMER,
            ])],
            'condition_notes' => ['nullable', 'string'],
        ]);

        // Captured before the transaction detaches the lease link (for
        // returned/in_stock) so there's still someone to notify afterward.
        $customer = $equipmentUnit->currentLease?->customer;

        DB::transaction(function () use ($equipmentUnit, $data) {
            if ($data['status'] !== EquipmentUnit::STATUS_OWNED_BY_CUSTOMER) {
                LeaseAgreement::where('equipment_unit_id', $equipmentUnit->id)
                    ->update(['equipment_unit_id' => null]);
            }

            $equipmentUnit->update([
                'status' => $data['status'],
                'condition_notes' => $data['condition_notes'] ?? $equipmentUnit->condition_notes,
                'updated_by' => Auth::id(),
            ]);
        });

        $equipmentUnit->refresh()->load(['currentLease.customer:id,name,email', 'serviceRecords.performedBy:id,name', 'updatedBy:id,name']);

        $message = match ($data['status']) {
            EquipmentUnit::STATUS_OWNED_BY_CUSTOMER => "Congratulations — you now own your {$equipmentUnit->model}!",
            EquipmentUnit::STATUS_RETURNED => "Your {$equipmentUnit->model} has been marked returned.",
            default => "Your {$equipmentUnit->model} is no longer on your lease.",
        };
        if ($customer && ($customer->customerProfile?->status_change_emails ?? true)) {
            $customer->notify(new EquipmentStatusChangedNotification($equipmentUnit, $message));
        }

        return response()->json(['data' => $this->present($equipmentUnit, includeHistory: true)]);
    }

    /**
     * Leases with no unit on them yet — the picker on the assign dialog.
     * Exposed under the equipment module rather than the lease module so an
     * admin restricted to equipment_tracking can still complete an assignment.
     */
    public function assignableLeases()
    {
        $leases = LeaseAgreement::query()
            ->whereNull('equipment_unit_id')
            ->with('customer:id,name,email')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $leases->map(fn (LeaseAgreement $lease) => [
                'id' => $lease->id,
                'application_id' => $lease->application_id,
                'customer_id' => $lease->customer_id,
                'customer_name' => $lease->customer?->name,
                'customer_email' => $lease->customer?->email,
                'term_months' => $lease->term_months,
                'start_date' => $lease->start_date?->toDateString(),
                'monthly_rental_payment' => $lease->monthly_rental_payment,
            ]),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(?EquipmentUnit $unit = null): array
    {
        return [
            'model' => [$unit ? 'sometimes' : 'required', 'string', 'max:255'],
            'serial_number' => [
                $unit ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('equipment_units', 'serial_number')->ignore($unit?->id),
            ],
            'vin' => ['nullable', 'string', 'max:255'],
            'condition_notes' => ['nullable', 'string'],
            'delivery_date' => ['nullable', 'date'],
            'expected_return_or_ownership_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(EquipmentUnit::STATUSES)],
            // Phase 2 hook: storable now so units can be pre-tagged, but nothing reads it yet.
            'gps_device_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, int> */
    private function statusCounts(): array
    {
        $counts = EquipmentUnit::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = ['total' => (int) $counts->sum()];

        foreach (EquipmentUnit::STATUSES as $status) {
            $result[$status] = (int) ($counts[$status] ?? 0);
        }

        return $result;
    }

    private function present(EquipmentUnit $unit, bool $includeHistory = false): array
    {
        $lease = $unit->relationLoaded('currentLease') ? $unit->currentLease : null;

        $payload = array_merge($unit->toArray(), [
            'is_assignable' => $unit->isAssignable(),
            'current_lease' => $lease ? $this->presentLease($lease) : null,
        ]);

        if ($includeHistory) {
            $payload['service_records'] = $unit->serviceRecords
                ->sortByDesc('service_date')
                ->values()
                ->map(fn ($record) => array_merge($record->toArray(), [
                    'performed_by_name' => $record->performedBy?->name,
                ]));
        }

        return $payload;
    }

    private function presentLease(LeaseAgreement $lease): array
    {
        return [
            'id' => $lease->id,
            'application_id' => $lease->application_id,
            'customer_id' => $lease->customer_id,
            'customer_name' => $lease->customer?->name,
            'customer_email' => $lease->customer?->email,
            'term_months' => $lease->term_months,
            'start_date' => $lease->start_date?->toDateString(),
            'renewal_date' => $lease->renewal_date?->toDateString(),
            'ownership_status' => $lease->ownership_status,
        ];
    }
}
