<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AutopayPaymentReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AutopayReminderTest extends TestCase
{
    use RefreshDatabase;

    private function leaseFor(User $customer, bool $autopay = true): LeaseAgreement
    {
        return LeaseAgreement::factory()->create([
            'customer_id' => $customer->id,
            'autopay_enabled' => $autopay,
        ]);
    }

    public function test_reminder_is_sent_for_an_autopay_payment_due_in_seven_days(): void
    {
        Notification::fake();

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $lease = $this->leaseFor($customer);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'due_date' => now()->addDays(7),
        ]);

        $this->artisan('payments:send-autopay-reminders')->assertSuccessful();

        Notification::assertSentTo($customer, AutopayPaymentReminderNotification::class);
    }

    public function test_reminder_is_not_sent_when_autopay_is_disabled(): void
    {
        Notification::fake();

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $lease = $this->leaseFor($customer, autopay: false);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'due_date' => now()->addDays(7),
        ]);

        $this->artisan('payments:send-autopay-reminders')->assertSuccessful();

        Notification::assertNotSentTo($customer, AutopayPaymentReminderNotification::class);
    }

    public function test_reminder_is_not_sent_for_a_payment_not_due_in_seven_days(): void
    {
        Notification::fake();

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $lease = $this->leaseFor($customer);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'due_date' => now()->addDays(3),
        ]);

        $this->artisan('payments:send-autopay-reminders')->assertSuccessful();

        Notification::assertNotSentTo($customer, AutopayPaymentReminderNotification::class);
    }

    public function test_reminder_is_not_sent_for_an_already_paid_payment(): void
    {
        Notification::fake();

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $lease = $this->leaseFor($customer);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'due_date' => now()->addDays(7),
            'status' => Payment::STATUS_PAID,
            'paid_date' => now(),
        ]);

        $this->artisan('payments:send-autopay-reminders')->assertSuccessful();

        Notification::assertNotSentTo($customer, AutopayPaymentReminderNotification::class);
    }

    public function test_reminder_is_not_sent_when_customer_opted_out(): void
    {
        Notification::fake();

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $customer->customerProfile()->create(['payment_reminder_emails' => false]);
        $lease = $this->leaseFor($customer);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'due_date' => now()->addDays(7),
        ]);

        $this->artisan('payments:send-autopay-reminders')->assertSuccessful();

        Notification::assertNotSentTo($customer, AutopayPaymentReminderNotification::class);
    }
}
