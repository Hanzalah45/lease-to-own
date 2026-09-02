<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\EquipmentServiceRecord;
use App\Models\EquipmentUnit;
use Illuminate\Http\Request;

/**
 * The service/maintenance log kept against one equipment unit. Phase 1 keeps
 * this deliberately plain — a date, what was done, and which staff member did
 * it — since the client has not specified a maintenance workflow yet.
 *
 * Nested under /admin/equipment-units/{equipmentUnit}, so it inherits the
 * permission:equipment_tracking gate from the parent route group.
 */
class EquipmentServiceRecordController extends Controller
{
    public function index(EquipmentUnit $equipmentUnit)
    {
        $records = $equipmentUnit->serviceRecords()
            ->with('performedBy:id,name')
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $records->map(fn ($record) => $this->present($record))]);
    }

    public function store(Request $request, EquipmentUnit $equipmentUnit)
    {
        $data = $request->validate([
            'service_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        $record = $equipmentUnit->serviceRecords()->create([
            'service_date' => $data['service_date'],
            'description' => $data['description'],
            // Always the signed-in staff member — not client-supplied, so the
            // log cannot be attributed to someone else.
            'performed_by' => $request->user()->id,
        ]);

        $record->load('performedBy:id,name');

        return response()->json(['data' => $this->present($record)], 201);
    }

    public function destroy(EquipmentUnit $equipmentUnit, EquipmentServiceRecord $serviceRecord)
    {
        // Guard against /equipment-units/1/service-records/99 where 99 belongs
        // to a different unit — route model binding resolves them independently.
        abort_unless($serviceRecord->equipment_unit_id === $equipmentUnit->id, 404);

        $serviceRecord->delete();

        return response()->noContent();
    }

    private function present(EquipmentServiceRecord $record): array
    {
        return array_merge($record->toArray(), [
            'performed_by_name' => $record->performedBy?->name,
        ]);
    }
}
