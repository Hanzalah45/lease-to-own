<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use Illuminate\Http\Request;

/**
 * Read-only view of the equipment the signed-in customer holds. Scoped through
 * their own lease agreements, so a customer can never see another customer's
 * serial numbers, and there is no write path at all — condition, status and
 * service history are staff-maintained (see Admin\EquipmentUnitController).
 *
 * Internal fields stay out of the payload: condition_notes is an internal
 * assessment and gps_device_id is Phase 2 plumbing.
 */
class EquipmentController extends Controller
{
    public function index(Request $request)
    {
        $leases = $request->user()
            ->leaseAgreements()
            ->whereNotNull('equipment_unit_id')
            ->with('equipmentUnit.serviceRecords')
            ->latest()
            ->get();

        $units = $leases
            ->filter(fn (LeaseAgreement $lease) => $lease->equipmentUnit !== null)
            ->map(fn (LeaseAgreement $lease) => $this->present($lease->equipmentUnit, $lease))
            ->values();

        return response()->json(['data' => $units]);
    }

    private function present(EquipmentUnit $unit, LeaseAgreement $lease): array
    {
        return [
            'id' => $unit->id,
            'model' => $unit->model,
            'serial_number' => $unit->serial_number,
            'vin' => $unit->vin,
            'status' => $unit->status,
            'delivery_date' => $unit->delivery_date?->toDateString(),
            'expected_return_or_ownership_date' => $unit->expected_return_or_ownership_date?->toDateString(),
            'lease_agreement_id' => $lease->id,
            'lease_term_months' => $lease->term_months,
            'lease_ownership_status' => $lease->ownership_status,
            'last_service_date' => $unit->serviceRecords->max('service_date')?->toDateString(),
            'service_records_count' => $unit->serviceRecords->count(),
        ];
    }
}
