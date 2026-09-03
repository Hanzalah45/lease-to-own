<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\ApplicationInfoRequest;
use App\Models\Payment;
use App\Models\RiskProfile;
use App\Models\User;
use App\Notifications\ApplicationStatusChangedNotification;
use App\Services\ApplicationCreationService;
use App\Services\LeaseEngine;
use App\Services\RiskScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Milestone 2/3 — turns the admin New Application wizard into a real,
 * persisted Application + LeaseAgreement (+ EquipmentUnit, CustomerProfile,
 * RiskProfile). A LeaseAgreement is created alongside the Application at
 * submission time (it carries the agreed terms); the Payment schedule is
 * only generated once the application reaches "funded_paid" — see update().
 */
class ApplicationController extends Controller
{
    public function index()
    {
        $applications = Application::with([
            'customer:id,name,email,phone',
            'createdBy:id,name',
            'reviewedBy:id,name',
            'leaseAgreement.equipmentUnit',
        ])->latest()->get();

        return response()->json(['data' => $applications]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'registered_customer_id' => ['required', 'integer', 'exists:users,id'],
            'cell_phone' => ['nullable', 'string', 'max:30'],
            'mailing_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'zip' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(365 * 120)->toDateString()],
            'drivers_license' => ['nullable', 'string', 'max:60'],
            'id_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],

            'residence_type' => ['nullable', Rule::in(['rent_apartment', 'own_single', 'own_multi', 'rent_house', 'other'])],
            'years_at_residence' => ['nullable', 'string', 'max:10'],
            'income_source' => ['nullable', 'string', 'max:30'],
            'gross_monthly_income' => ['nullable', 'numeric', 'min:0'],
            'move_notification_agreed' => ['required', 'accepted'],

            'sales_person' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'in:new,used'],
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'ldw' => ['nullable', 'in:yes,no'],
            'cash_price' => ['required', 'numeric', 'min:0'],
            'year' => ['nullable', 'string', 'max:10'],
            'promo_code' => ['nullable', 'string', 'max:60'],

            'term_months' => ['required', 'integer', 'min:1', 'max:120'],
            'monthly_rental' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'security_deposit' => ['nullable', 'numeric', 'min:0'],
            'payment_due_day' => ['nullable', 'integer', 'between:1,31'],
            'autopay' => ['nullable', 'in:yes,no'],
        ]);

        $customer = User::findOrFail($data['registered_customer_id']);

        $application = ApplicationCreationService::create(
            $customer,
            $data,
            $request->file('id_document'),
            $data['sales_person'] ?? null,
            Auth::id(),
        );

        return response()->json(['data' => $this->present($application)], 201);
    }

    public function show(Application $application)
    {
        return response()->json(['data' => $this->present($application)]);
    }

    public function update(Request $request, Application $application)
    {
        $equipmentUnitId = $application->leaseAgreement?->equipment_unit_id;

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Application::ALL_STATUSES)],
            'status_notes' => ['sometimes', 'nullable', 'string'],
            'signature_received' => ['sometimes', 'boolean'],
            'deposit_received' => ['sometimes', 'boolean'],
            'lease' => ['sometimes', 'array'],
            'lease.term_months' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'lease.monthly_rental_payment' => ['sometimes', 'numeric', 'min:0'],
            'lease.sales_tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'lease.security_deposit' => ['sometimes', 'numeric', 'min:0'],
            'lease.autopay_enabled' => ['sometimes', 'boolean'],
            'lease.ldw_selected' => ['sometimes', 'boolean'],
            'lease.promo_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'equipment' => ['sometimes', 'array'],
            'equipment.model' => ['sometimes', 'string', 'max:255'],
            'equipment.serial_number' => ['sometimes', 'string', 'max:255', Rule::unique('equipment_units', 'serial_number')->ignore($equipmentUnitId)],
            'equipment.condition_notes' => ['sometimes', 'nullable', 'string'],
            'customer' => ['sometimes', 'array'],
            'customer.address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.state' => ['sometimes', 'nullable', 'string', 'max:2'],
            'customer.zip' => ['sometimes', 'nullable', 'string', 'max:10'],
            'customer.residence_type' => ['sometimes', 'nullable', 'string', 'max:30'],
            'risk' => ['sometimes', 'array'],
            'risk.identity_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'risk.employment_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'risk.bank_verification_status' => ['sometimes', Rule::in(['pending', 'verified', 'failed'])],
            'risk.background_check_status' => ['sometimes', Rule::in(['pending', 'clear', 'flagged'])],
            'risk.background_check_notes' => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('status', $data) && $data['status'] !== $application->status) {
            $legalNextStatuses = Application::LEGAL_STATUS_TRANSITIONS[$application->status] ?? [];
            abort_unless(
                in_array($data['status'], $legalNextStatuses, true),
                422,
                "This application cannot move from \"{$application->status}\" to \"{$data['status']}\".",
            );
        }

        if (array_key_exists('status', $data)) {
            $isNeedsInfo = $data['status'] === Application::STATUS_NEEDS_INFO;

            $application->update([
                'status' => $data['status'],
                // needs_info asks live in application_info_requests instead
                // (see below) — status_notes stays reserved for every other
                // status change (decline reasons, etc.).
                'status_notes' => $isNeedsInfo ? $application->status_notes : ($data['status_notes'] ?? $application->status_notes),
                'reviewed_by' => Auth::id(),
            ]);

            // Guards against a duplicate open request from a race between two
            // admins (or a double-submit) hitting this endpoint at once — the
            // customer's reply only ever closes the newest one, so an older
            // duplicate would otherwise stay open forever.
            if ($isNeedsInfo && ! $application->infoRequests()->whereNull('replied_at')->exists()) {
                $application->infoRequests()->create([
                    'requested_by_user_id' => Auth::id(),
                    'request_text' => $data['status_notes'] ?? '',
                ]);
            }

            // Terms are locked in once approved, so the payment schedule is
            // generated here — well before "funded_paid", which just means
            // the lease is live and funds have moved.
            if ($data['status'] === Application::STATUS_APPROVED) {
                $lease = $application->leaseAgreement;
                if ($lease && ! $lease->payments()->exists()) {
                    LeaseEngine::generatePaymentSchedule($lease);
                }
            }

            // "...emails" is the field name from the customer's own preferences UI, but this
            // app has no email channel wired up yet — the toggle controls the in-app
            // notification instead, since that's the only one that exists.
            if ($application->customer->customerProfile?->status_change_emails ?? true) {
                $application->customer->notify(new ApplicationStatusChangedNotification($application->fresh()));
            }
        }

        if (array_key_exists('signature_received', $data) || array_key_exists('deposit_received', $data)) {
            $application->update(array_filter([
                'signature_received' => $data['signature_received'] ?? null,
                'deposit_received' => $data['deposit_received'] ?? null,
            ], fn ($v) => $v !== null));
        }

        if ($lease = $application->leaseAgreement) {
            if (! empty($data['lease'])) {
                abort_unless($request->user()->hasAdminPermission(AdminPermission::CONTRACT_GENERATION), 403, 'You do not have permission to edit lease terms.');
                abort_if($lease->contract()->exists(), 422, 'This lease agreement has already been signed and its terms can no longer be changed.');

                $termsChanged = isset($data['lease']['term_months']) || isset($data['lease']['monthly_rental_payment']);

                $lease->update(array_merge($data['lease'], ['updated_by' => Auth::id()]));
                if ($termsChanged) {
                    $lease->update([
                        'total_rental_purchase_price' => LeaseEngine::totalRentalPurchasePrice(
                            (float) $lease->monthly_rental_payment,
                            (int) $lease->term_months,
                        ),
                    ]);

                    // A schedule may already exist from the approval step — rebuild it
                    // at the new terms rather than leaving stale rows on the books.
                    if ($lease->payments()->exists()) {
                        abort_if(
                            $lease->payments()->where('status', Payment::STATUS_PAID)->exists(),
                            422,
                            'Cannot change the term or rental amount once a payment has been made against this lease.',
                        );
                        LeaseEngine::regeneratePaymentSchedule($lease);
                    }
                }
            }

            if (! empty($data['equipment']) && $lease->equipmentUnit) {
                abort_unless($request->user()->hasAdminPermission(AdminPermission::EQUIPMENT_TRACKING), 403, 'You do not have permission to edit equipment records.');
                $lease->equipmentUnit->update(array_merge($data['equipment'], ['updated_by' => Auth::id()]));
            }
        }

        if (! empty($data['customer'])) {
            $application->customer->customerProfile()->updateOrCreate(
                ['user_id' => $application->customer_id],
                array_merge($data['customer'], ['updated_by' => Auth::id()]),
            );
        }

        if (! empty($data['risk'])) {
            abort_unless($request->user()->hasAdminPermission(AdminPermission::RISK_ASSESSMENT), 403, 'You do not have permission to edit the risk profile.');

            $riskProfile = RiskProfile::updateOrCreate(
                ['customer_id' => $application->customer_id],
                array_merge($data['risk'], ['updated_by' => Auth::id()]),
            );
            RiskScoringService::recomputeScore($riskProfile);
        }

        return response()->json(['data' => $this->present($application->fresh())]);
    }

    public function destroy(Application $application)
    {
        $application->delete();

        return response()->json(null, 204);
    }

    /** Streams the applicant's current ID document on file — never exposed via a public URL. */
    public function idDocument(Application $application)
    {
        $path = $application->customer->customerProfile?->government_id_document_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    /** Streams the specific document attached to one historical info-request reply — not just whatever's current. */
    public function infoRequestDocument(Application $application, ApplicationInfoRequest $infoRequest)
    {
        abort_unless($infoRequest->application_id === $application->id, 404);
        abort_unless($infoRequest->reply_document_path && Storage::disk('local')->exists($infoRequest->reply_document_path), 404);

        return Storage::disk('local')->download($infoRequest->reply_document_path);
    }

    /** Attaches the LeaseEngine's live EPO figures to the loaded lease agreement, if one exists. */
    private function present(Application $application): array
    {
        $application->load([
            'createdBy:id,name',
            'customer.customerProfile.updatedBy:id,name',
            'customer.riskProfile.redFlags.resolvedBy:id,name',
            'customer.riskProfile.updatedBy:id,name',
            'reviewedBy:id,name',
            'leaseAgreement.updatedBy:id,name',
            'leaseAgreement.equipmentUnit' => fn ($query) => $query->withCount('serviceRecords'),
            'leaseAgreement.equipmentUnit.updatedBy:id,name',
            'leaseAgreement.payments',
            'leaseAgreement.contract',
            'leaseAgreement.contracts.voidedBy:id,name',
            'leaseAgreement.contracts.signer:id,name',
            'dealerNotes.author:id,name',
            'infoRequests.requestedBy:id,name',
        ]);

        $payload = $application->toArray();

        $payload['info_requests'] = $application->infoRequests->map(fn (ApplicationInfoRequest $r) => [
            'id' => $r->id,
            'requested_by' => $r->requestedBy?->name,
            'request_text' => $r->request_text,
            'requested_at' => $r->created_at,
            'reply_text' => $r->reply_text,
            'reply_has_document' => (bool) $r->reply_document_path,
            'replied_at' => $r->replied_at,
        ])->values();

        if ($lease = $application->leaseAgreement) {
            $payload['lease_agreement']['sales_tax_amount'] = $lease->salesTaxAmount();
            $payload['lease_agreement']['total_monthly_payment'] = $lease->totalMonthlyPayment();
            $payload['lease_agreement']['payments_made'] = $lease->paymentsMadeCount();
            $payload['lease_agreement']['epo_today'] = LeaseEngine::epoToday($lease);
            $payload['lease_agreement']['epo_schedule'] = LeaseEngine::fullSchedule($lease);
        }

        return $payload;
    }
}
