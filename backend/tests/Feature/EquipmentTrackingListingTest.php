<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real gap found live 2026-09-25 (call with Joel): attaching equipment and
 * pricing to an in-progress application immediately creates an EquipmentUnit
 * row with status=leased (ApplicationCreationService::buildEquipmentAndLease),
 * long before the unit is actually handed over — but Equipment Tracking
 * showed it right away, indistinguishable from a unit that had really gone
 * out the door. Covers the fix: the list (and its status counts) now only
 * show a leased unit once delivery_date is set, which happens at the real
 * delivery event ("Mark Delivered & Paid") or an explicit manual Assign.
 */
class EquipmentTrackingListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_unit_reserved_by_an_in_progress_application_is_hidden_from_the_list(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 5000,
            'term_months' => 36,
            'monthly_rental' => 200,
        ])->assertOk();

        $unit = EquipmentUnit::sole();
        $this->assertSame(EquipmentUnit::STATUS_LEASED, $unit->status);
        $this->assertNull($unit->delivery_date);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/equipment-units');
        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $this->assertSame(0, $response->json('meta.counts.leased'));
        $this->assertSame(0, $response->json('meta.counts.total'));
    }

    public function test_the_unit_appears_once_it_is_actually_delivered(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 5000,
            'term_months' => 36,
            'monthly_rental' => 200,
        ])->assertOk();

        $unit = EquipmentUnit::sole();
        $unit->update(['delivery_date' => now()->toDateString()]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/equipment-units');
        $response->assertJsonCount(1, 'data');
        $this->assertSame(1, $response->json('meta.counts.leased'));
    }

    public function test_a_unit_manually_assigned_from_stock_appears_immediately(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $unit = EquipmentUnit::factory()->create(['status' => EquipmentUnit::STATUS_IN_STOCK]);
        $lease = LeaseAgreement::factory()->create(['equipment_unit_id' => null]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/equipment-units/{$unit->id}/assign", [
            'lease_agreement_id' => $lease->id,
        ])->assertOk();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/equipment-units');
        $response->assertJsonCount(1, 'data');
        $this->assertSame($unit->id, $response->json('data.0.id'));
    }

    public function test_in_stock_and_returned_units_are_never_hidden(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        EquipmentUnit::factory()->create(['status' => EquipmentUnit::STATUS_IN_STOCK]);
        EquipmentUnit::factory()->create(['status' => EquipmentUnit::STATUS_RETURNED]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/equipment-units');
        $response->assertJsonCount(2, 'data');
    }
}
