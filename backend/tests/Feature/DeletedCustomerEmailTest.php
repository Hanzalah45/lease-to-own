<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real case (client, Joel, 2026-10-12): a customer's accidental duplicate
 * account was deleted, but the soft-deleted row kept the email reserved, so
 * the real customer's mistyped email could not be corrected. Deleting an
 * account now frees its email, and a mistyped email domain is caught on entry.
 */
class DeletedCustomerEmailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    public function test_deleting_a_customer_frees_the_email_so_the_real_account_can_be_corrected(): void
    {
        $admin = $this->admin();
        $duplicate = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'jordan@example.com']);
        $real = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'jordan@example.comt', 'status' => 'pending']);

        // Before deleting, the correction is refused: the address is taken.
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/customers/{$real->id}", ['email' => 'jordan@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/customers/{$duplicate->id}")->assertNoContent();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/customers/{$real->id}", ['email' => 'jordan@example.com'])
            ->assertOk();
        $this->assertSame('jordan@example.com', $real->fresh()->email);
    }

    public function test_a_new_account_can_use_the_address_of_a_deleted_one(): void
    {
        $admin = $this->admin();
        $gone = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'again@example.com']);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/customers/{$gone->id}")->assertNoContent();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/customers', [
            'name' => 'Same Person',
            'email' => 'again@example.com',
            'phone' => '9365550100',
            'password' => 'Correct-Horse-9',
        ])->assertCreated();
    }

    public function test_the_deleted_row_keeps_a_readable_address_that_can_never_receive_mail(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'readable@example.com']);
        $id = $user->id;

        $user->delete();

        $row = User::withTrashed()->findOrFail($id);
        $this->assertTrue($row->trashed());
        $this->assertSame("deleted-{$id}-readable@example.com.invalid", $row->email);
    }

    public function test_the_parked_address_always_fits_the_column(): void
    {
        $long = str_repeat('a', 240).'@example.com'; // 252 characters, valid length
        $parked = User::parkedEmail(123456, $long);

        $this->assertLessThanOrEqual(255, strlen($parked));
        $this->assertStringStartsWith('deleted-123456-', $parked);
        $this->assertStringEndsWith('.invalid', $parked);
        // Parking twice does not pile on more text.
        $this->assertSame($parked, User::parkedEmail(123456, $parked));
    }

    public function test_a_permanent_delete_leaves_the_address_alone(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'permanent@example.com']);

        $user->forceDelete();

        $this->assertNull(User::withTrashed()->where('email', 'like', '%permanent@example.com%')->first());
        // And the address is free again.
        User::factory()->create(['email' => 'permanent@example.com']);
        $this->assertSame(1, User::where('email', 'permanent@example.com')->count());
    }

    public function test_the_migration_parks_accounts_that_were_deleted_earlier(): void
    {
        $old = User::factory()->create(['email' => 'earlier@example.com']);
        // Deleted before this change existed: the row is flagged deleted but the address is untouched.
        DB::table('users')->where('id', $old->id)->update(['deleted_at' => now()]);
        $alreadyParked = User::factory()->create(['email' => 'manual.deleted9@example.invalid']);
        DB::table('users')->where('id', $alreadyParked->id)->update(['deleted_at' => now()]);
        $live = User::factory()->create(['email' => 'live@example.com']);

        $migration = require database_path('migrations/2026_10_13_120000_park_emails_of_deleted_users.php');
        $migration->up();
        $migration->up(); // running it again changes nothing

        $this->assertSame("deleted-{$old->id}-earlier@example.com.invalid", User::withTrashed()->find($old->id)->email);
        $this->assertSame('manual.deleted9@example.invalid', User::withTrashed()->find($alreadyParked->id)->email);
        $this->assertSame('live@example.com', $live->fresh()->email);
    }

    public function test_a_mistyped_email_domain_is_rejected_when_the_check_is_on(): void
    {
        config(['app.validate_email_dns' => true]);

        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/customers', [
            'name' => 'Typo Person',
            'email' => 'typo@gmail.comt',
            'phone' => '9365550100',
            'password' => 'Correct-Horse-9',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The part after the @ in this email address does not exist. Please check it for typos.');
    }

    public function test_the_domain_check_is_off_by_default_outside_production(): void
    {
        $this->assertFalse(config('app.validate_email_dns'));

        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/customers', [
            'name' => 'Offline Person',
            'email' => 'someone@gmail.comt',
            'phone' => '9365550100',
            'password' => 'Correct-Horse-9',
        ])->assertCreated();
    }
}
