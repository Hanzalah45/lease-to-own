<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\QuickbooksConnection;
use App\Models\QuickbooksSyncSetting;
use App\Models\User;
use App\Services\QuickbooksSyncer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sending collected payments to the client's QuickBooks (signed plan, milestone 7): one balanced journal entry per payment,
 * each part of it on the account the accountant chose, sent once, safe to repeat, and reversed if the payment is taken back.
 * Intuit is faked below; the same flow was also run end to end in a browser against a local stand-in (tests/fake-intuit).
 */
class QuickbooksSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> journal entries QuickBooks holds, by DocNumber */
    private array $entries = [];

    /** @var list<Request> every request made to the accounting API */
    private array $calls = [];

    /** @var list<array<string, mixed>> */
    private array $quickbooksAccounts = [];

    private int $nextId = 500;

    /** When set, the next journal entry create answers with this instead of creating it. */
    private mixed $createOutcome = null;

    /** When set, the next token refresh answers with this. */
    private mixed $refreshOutcome = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 15:00:00', 'UTC'));
        config([
            'services.quickbooks.client_id' => 'fake-client-id',
            'services.quickbooks.client_secret' => 'fake-client-secret',
            'services.quickbooks.redirect_uri' => 'http://localhost:8010/api/quickbooks/callback',
            // Intuit's real endpoints, whatever the local .env points at (a local stand-in for Intuit is used in the browser).
            'services.quickbooks.authorize_url' => 'https://appcenter.intuit.com/connect/oauth2',
            'services.quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
            'services.quickbooks.revoke_url' => 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke',
            'services.quickbooks.api_base' => 'https://qbo.test',
            'app.frontend_url' => 'http://localhost:3000',
        ]);
        $this->quickbooksAccounts = collect(range(1, 12))->map(fn ($i) => [
            'Id' => (string) (100 + $i),
            'Name' => "Account {$i}",
            'FullyQualifiedName' => "Account {$i}",
            'AccountType' => 'Income',
            'AccountSubType' => 'SalesOfProductIncome',
            'Active' => true,
        ])->all();
        $this->fakeQuickbooks();
    }

    private function fakeQuickbooks(): void
    {
        Http::fake([
            'qbo.test/v3/company/*/journalentry*' => function (Request $request) {
                $this->calls[] = $request;
                if ($this->createOutcome !== null) {
                    $outcome = $this->createOutcome;
                    $this->createOutcome = null;

                    return $outcome instanceof \Closure ? $outcome($request) : $outcome;
                }
                $entry = $request->data();
                $entry['Id'] = (string) $this->nextId++;
                $this->entries[$entry['DocNumber']] = $entry;

                return Http::response(['JournalEntry' => $entry, 'time' => 'now'], 200);
            },
            'qbo.test/v3/company/*/account*' => function (Request $request) {
                $this->calls[] = $request;
                $account = $request->data() + ['Id' => (string) $this->nextId++, 'Active' => true];
                $this->quickbooksAccounts[] = $account;

                return Http::response(['Account' => $account], 200);
            },
            'qbo.test/v3/company/*/query*' => function (Request $request) {
                $this->calls[] = $request;
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $sql = $query['query'] ?? '';
                if (preg_match("/from JournalEntry where DocNumber = '([^']+)'/", $sql, $m)) {
                    $found = isset($this->entries[$m[1]]) ? [$this->entries[$m[1]]] : [];

                    return Http::response(['QueryResponse' => $found ? ['JournalEntry' => $found] : (object) []], 200);
                }
                if (preg_match("/from Account where Name = '([^']+)'/", $sql, $m)) {
                    $found = array_values(array_filter($this->quickbooksAccounts, fn ($a) => $a['Name'] === $m[1]));

                    return Http::response(['QueryResponse' => $found ? ['Account' => $found] : (object) []], 200);
                }

                return Http::response(['QueryResponse' => ['Account' => $this->quickbooksAccounts]], 200);
            },
            'oauth.platform.intuit.com/*' => fn () => $this->refreshOutcome ?? Http::response([
                'access_token' => 'refreshed-access',
                'refresh_token' => 'refreshed-refresh',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 8726400,
            ], 200),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    private function connect(?User $by = null, array $overrides = []): QuickbooksConnection
    {
        return QuickbooksConnection::create($overrides + [
            'realm_id' => '9130',
            'access_token' => 'good-access',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'good-refresh',
            'refresh_token_expires_at' => now()->addDays(100),
            'connected_by' => ($by ?? $this->admin())->id,
        ]);
    }

    /** Switched on from 1 October, every kind of money on its own account. */
    private function enableSync(array $overrides = []): QuickbooksSyncSetting
    {
        $accounts = [];
        foreach (array_keys(QuickbooksSyncSetting::ACCOUNTS) as $i => $key) {
            $accounts[$key] = ['id' => (string) (101 + $i), 'name' => 'Account '.($i + 1)];
        }

        $settings = QuickbooksSyncSetting::current();
        $settings->update($overrides + ['sync_enabled' => true, 'sync_from' => '2026-10-01', 'accounts' => $accounts]);

        return $settings;
    }

    /** Rent $200 at 8.25% tax: a monthly payment is $216.50, of which $16.50 is sales tax. */
    private function lease(string $name = 'Report Customer'): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => $name]);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_FINISHED]);

        return LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0.0825,
            'ldw_selected' => false,
            'ldw_amount' => 0,
        ]);
    }

    private function paid(LeaseAgreement $lease, string $type, float $amount, string $date, array $extra = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id,
            'type' => $type,
            'amount' => $amount,
            'due_date' => $date,
            'paid_date' => $date,
            'status' => Payment::STATUS_PAID,
            'method' => 'ach',
        ], $extra));
    }

    /** @return array{debit: array<string, mixed>, credits: array<string, float>} the entry's debit line and its credits by account id */
    private function lines(array $entry): array
    {
        $credits = [];
        $debit = null;
        foreach ($entry['Line'] as $line) {
            $detail = $line['JournalEntryLineDetail'];
            if ($detail['PostingType'] === 'Debit') {
                $debit = ['account' => $detail['AccountRef']['value'], 'amount' => $line['Amount']];
            } else {
                $credits[$detail['AccountRef']['value']] = ($credits[$detail['AccountRef']['value']] ?? 0) + $line['Amount'];
            }
        }

        return ['debit' => $debit, 'credits' => $credits];
    }

    /** The account id chosen for a kind of money by enableSync(). */
    private function account(string $key): string
    {
        return QuickbooksSyncSetting::current()->accountId($key);
    }

    private function runSync(): array
    {
        return app(QuickbooksSyncer::class)->run();
    }

    public function test_a_monthly_payment_becomes_one_balanced_entry_with_the_tax_on_its_own_account(): void
    {
        $this->connect();
        $this->enableSync();
        $payment = $this->paid($this->lease('Jane Doe'), Payment::TYPE_RENTAL, 216.50, '2026-10-15');

        $result = $this->runSync();

        $this->assertSame(1, $result['sent']);
        $this->assertNull($result['stopped']);
        $entry = $this->entries['PL-'.$payment->id];
        $this->assertSame('2026-10-15', $entry['TxnDate']);

        $lines = $this->lines($entry);
        $this->assertSame($this->account('deposit_account'), $lines['debit']['account']);
        $this->assertEqualsWithDelta(216.50, $lines['debit']['amount'], 0.001);
        $this->assertEqualsWithDelta(200.00, $lines['credits'][$this->account('rent_income')], 0.001);
        $this->assertEqualsWithDelta(16.50, $lines['credits'][$this->account('sales_tax')], 0.001);
        $this->assertEqualsWithDelta(216.50, array_sum($lines['credits']), 0.001);
        $this->assertStringContainsString('Jane Doe', $entry['Line'][0]['Description']);

        $payment->refresh();
        $this->assertSame('synced', $payment->quickbooks_sync_status);
        $this->assertSame((string) ($this->nextId - 1), $payment->quickbooks_record_id);
        $this->assertNotNull($payment->quickbooks_synced_at);
        $this->assertNull($payment->quickbooks_sync_error);
    }

    public function test_the_tracking_fee_and_first_month_charge_splits_four_ways_and_its_mirror_row_is_not_sent_twice(): void
    {
        $this->connect();
        $this->enableSync();
        $lease = $this->lease();
        $charge = $this->paid($lease, Payment::TYPE_PICKUP_BALANCE, 366.50, '2026-10-12', ['method' => 'card', 'card_fee_amount' => 11.00, 'stripe_payment_intent_id' => 'pi_pickup']);
        $mirror = $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-12', ['method' => null]);

        $result = $this->runSync();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('skipped', $mirror->refresh()->quickbooks_sync_status);
        $this->assertNull($mirror->quickbooks_record_id);

        $lines = $this->lines($this->entries['PL-'.$charge->id]);
        $this->assertEqualsWithDelta(377.50, $lines['debit']['amount'], 0.001);
        $this->assertEqualsWithDelta(150.00, $lines['credits'][$this->account('tracking_fee_income')], 0.001);
        $this->assertEqualsWithDelta(200.00, $lines['credits'][$this->account('rent_income')], 0.001);
        $this->assertEqualsWithDelta(16.50, $lines['credits'][$this->account('sales_tax')], 0.001);
        $this->assertEqualsWithDelta(11.00, $lines['credits'][$this->account('card_fee_income')], 0.001);
    }

    public function test_a_deposit_a_late_fee_and_an_extra_payment_each_go_to_their_own_account_without_tax(): void
    {
        $this->connect();
        $this->enableSync();
        $lease = $this->lease();
        $deposit = $this->paid($lease, Payment::TYPE_DEPOSIT, 300, '2026-10-02');
        $late = $this->paid($lease, Payment::TYPE_LATE_FEE, 20, '2026-10-03');
        $extra = $this->paid($lease, Payment::TYPE_EXTRA_PAYMENT, 100, '2026-10-04', ['method' => 'check']);

        $this->assertSame(3, $this->runSync()['sent']);

        $this->assertSame([$this->account('security_deposit') => 300.0], $this->lines($this->entries['PL-'.$deposit->id])['credits']);
        $this->assertSame([$this->account('late_fee_income') => 20.0], $this->lines($this->entries['PL-'.$late->id])['credits']);
        $this->assertSame([$this->account('extra_payment') => 100.0], $this->lines($this->entries['PL-'.$extra->id])['credits']);
    }

    public function test_every_entry_balances_to_the_cent_for_awkward_amounts(): void
    {
        $this->connect();
        $this->enableSync();
        $lease = $this->lease();
        // Prorated amounts and card fees that do not split evenly.
        foreach ([[30.01, 0.90], [17.33, 0.52], [199.99, 6.00], [0.06, 0.0]] as $i => [$amount, $fee]) {
            $this->paid($lease, Payment::TYPE_RENTAL, $amount, '2026-10-0'.($i + 1), ['method' => $fee > 0 ? 'card' : 'ach', 'card_fee_amount' => $fee, 'stripe_payment_intent_id' => 'pi_'.$i]);
        }

        $this->assertSame(4, $this->runSync()['sent']);

        foreach ($this->entries as $entry) {
            $lines = $this->lines($entry);
            $this->assertSame(round($lines['debit']['amount'], 2), round(array_sum($lines['credits']), 2), $entry['DocNumber'].' does not balance');
        }
    }

    public function test_payments_before_the_start_day_and_unpaid_ones_are_never_sent(): void
    {
        $this->connect();
        $this->enableSync(['sync_from' => '2026-10-10']);
        $lease = $this->lease();
        $before = $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-09');
        $pending = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 216.50, 'due_date' => '2026-10-15', 'status' => Payment::STATUS_PENDING]);
        $failed = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 216.50, 'due_date' => '2026-10-16', 'status' => Payment::STATUS_FAILED]);
        $sent = $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-10');

        $this->assertSame(1, $this->runSync()['sent']);

        $this->assertSame(['PL-'.$sent->id], array_keys($this->entries));
        $this->assertNull($before->refresh()->quickbooks_sync_status);
        $this->assertNull($pending->refresh()->quickbooks_sync_status);
        $this->assertNull($failed->refresh()->quickbooks_sync_status);
    }

    public function test_running_again_sends_nothing_twice(): void
    {
        $this->connect();
        $this->enableSync();
        $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15');

        $this->assertSame(1, $this->runSync()['sent']);
        $creates = count($this->entries);
        $this->assertSame(0, $this->runSync()['sent']);

        $this->assertCount($creates, $this->entries);
        $posts = collect($this->calls)->filter(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'journalentry'))->count();
        $this->assertSame(1, $posts);
    }

    public function test_nothing_is_sent_while_switched_off_or_while_an_account_is_missing(): void
    {
        $this->connect();
        $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15');

        $this->enableSync(['sync_enabled' => false]);
        $this->assertSame(0, $this->runSync()['sent']);

        $accounts = QuickbooksSyncSetting::current()->accounts;
        unset($accounts['sales_tax']);
        QuickbooksSyncSetting::current()->update(['sync_enabled' => true, 'accounts' => $accounts]);
        $result = $this->runSync();
        $this->assertSame(0, $result['sent']);
        $this->assertNotNull($result['stopped']);

        $this->assertSame([], $this->calls);
    }

    public function test_nothing_is_sent_when_quickbooks_is_not_connected(): void
    {
        $this->enableSync();
        $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15');

        $result = $this->runSync();

        $this->assertSame(0, $result['sent']);
        $this->assertStringContainsString('not connected', $result['stopped']);
        $this->assertSame([], $this->calls);
    }

    public function test_an_expired_access_token_is_refreshed_and_both_new_tokens_are_kept(): void
    {
        $this->connect(null, ['access_token_expires_at' => now()->subMinute()]);
        $this->enableSync();
        $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15');

        $this->assertSame(1, $this->runSync()['sent']);

        $connection = QuickbooksConnection::first();
        $this->assertSame('refreshed-access', $connection->access_token);
        $this->assertSame('refreshed-refresh', $connection->refresh_token);
        $this->assertTrue($connection->access_token_expires_at->isFuture());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth.platform.intuit.com') && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'good-refresh');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'journalentry') && $r->hasHeader('Authorization', 'Bearer refreshed-access'));
    }

    public function test_a_token_quickbooks_rejects_is_refreshed_once_and_the_call_repeated(): void
    {
        $this->connect();
        $this->enableSync();
        $payment = $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15');
        $this->createOutcome = Http::response(['Fault' => ['Error' => [['Message' => 'message=AuthenticationFailed; errorCode=003200; statusCode=401']], 'type' => 'AUTHENTICATION']], 401);

        $this->assertSame(1, $this->runSync()['sent']);

        $this->assertSame('refreshed-access', QuickbooksConnection::first()->access_token);
        $this->assertSame('synced', $payment->refresh()->quickbooks_sync_status);
    }

    public function test_a_refresh_token_quickbooks_refuses_stops_the_run_and_asks_for_a_reconnect(): void
    {
        $this->connect(null, ['access_token_expires_at' => now()->subMinute()]);
        $this->enableSync();
        $payment = $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15');
        $this->refreshOutcome = Http::response(['error' => 'invalid_grant'], 400);

        $result = $this->runSync();

        $this->assertSame(0, $result['sent']);
        $this->assertStringContainsString('Reconnect QuickBooks', $result['stopped']);
        $this->assertTrue(QuickbooksConnection::first()->isRefreshTokenExpired());
        $this->assertSame([], $this->entries);
        $this->assertNull($payment->refresh()->quickbooks_sync_status);
    }

    public function test_a_payment_quickbooks_keeps_refusing_is_parked_after_three_tries_and_try_again_sends_it(): void
    {
        $this->connect();
        $this->enableSync();
        $lease = $this->lease();
        $bad = $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-14');
        $good = $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-15');
        $fault = fn () => Http::response(['Fault' => ['Error' => [['Message' => 'Business Validation Error', 'Detail' => 'Account Period Closed', 'code' => '6200']], 'type' => 'ValidationFault']], 400);

        for ($i = 1; $i <= 3; $i++) {
            $this->createOutcome = $fault();
            $result = $this->runSync();
            // The refusal belongs to that one payment: the next payment in line is still sent.
            $this->assertSame(1, $result['failed']);
            $this->assertSame($i === 1 ? 1 : 0, $result['sent']);
        }

        $bad->refresh();
        $this->assertSame('failed', $bad->quickbooks_sync_status);
        $this->assertSame(3, $bad->quickbooks_sync_attempts);
        $this->assertStringContainsString('Account Period Closed (code 6200)', $bad->quickbooks_sync_error);
        $this->assertSame('synced', $good->refresh()->quickbooks_sync_status);

        // Parked: a further run leaves it alone.
        $this->assertSame(0, $this->runSync()['sent']);

        $response = $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/quickbooks/sync/retry')->assertOk();
        $response->assertJsonPath('result.sent', 1);
        $this->assertSame('synced', $bad->refresh()->quickbooks_sync_status);
        $this->assertNull($bad->quickbooks_sync_error);
    }

    public function test_when_quickbooks_is_down_the_run_stops_and_the_payments_stay_waiting_without_being_counted_against(): void
    {
        $this->connect();
        $this->enableSync();
        $lease = $this->lease();
        $first = $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-14');
        $second = $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-15');
        $this->createOutcome = Http::response('Service Unavailable', 503);

        $result = $this->runSync();

        $this->assertSame(0, $result['sent']);
        $this->assertStringContainsString('busy or down', $result['stopped']);
        $this->assertNull($first->refresh()->quickbooks_sync_status);
        $this->assertSame(0, $first->quickbooks_sync_attempts);
        $this->assertNull($second->refresh()->quickbooks_sync_error);

        // It comes back: both go through.
        $this->assertSame(2, $this->runSync()['sent']);
    }

    public function test_an_entry_that_reached_quickbooks_without_its_answer_is_found_not_created_again(): void
    {
        $this->connect();
        $this->enableSync();
        $payment = $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15');

        // The create reaches QuickBooks and is stored, but the reply never arrives.
        $this->createOutcome = function (Request $request) {
            $entry = $request->data();
            $entry['Id'] = '777';
            $this->entries[$entry['DocNumber']] = $entry;
            throw new ConnectionException('cURL error 28: timed out');
        };
        $first = $this->runSync();
        $this->assertSame(0, $first['sent']);
        $this->assertStringContainsString('could not be reached', $first['stopped']);

        $second = $this->runSync();

        $this->assertSame(1, $second['sent']);
        $this->assertSame('777', $payment->refresh()->quickbooks_record_id);
        $posts = collect($this->calls)->filter(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'journalentry'))->count();
        $this->assertSame(1, $posts, 'The entry must not be created a second time.');
    }

    public function test_a_synced_payment_that_is_refunded_gets_one_reversing_entry(): void
    {
        $this->connect();
        $this->enableSync();
        $payment = $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15');
        $this->runSync();
        $original = $this->entries['PL-'.$payment->id];

        $payment->update(['status' => Payment::STATUS_REFUNDED]);
        $result = $this->runSync();

        $this->assertSame(1, $result['reversed']);
        $reversal = $this->entries['PL-R'.$payment->id];
        $this->assertSame('2026-10-20', $reversal['TxnDate']);
        // The reversal debits what the original credited and credits what it debited, for the same amounts.
        foreach ($reversal['Line'] as $i => $line) {
            $this->assertNotSame($original['Line'][$i]['JournalEntryLineDetail']['PostingType'], $line['JournalEntryLineDetail']['PostingType']);
            $this->assertEquals($original['Line'][$i]['Amount'], $line['Amount']);
        }
        $this->assertNotNull($payment->refresh()->quickbooks_reversal_id);

        // Once only.
        $this->assertSame(0, $this->runSync()['reversed']);
    }

    public function test_a_payment_that_was_refunded_before_it_was_ever_sent_is_not_sent_at_all(): void
    {
        $this->connect();
        $this->enableSync();
        $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15', ['status' => Payment::STATUS_REFUNDED]);

        $result = $this->runSync();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['reversed']);
        $this->assertSame([], $this->entries);
    }

    public function test_the_command_runs_the_sync(): void
    {
        $this->connect();
        $this->enableSync();
        $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15');

        $this->artisan('quickbooks:sync-payments')->expectsOutputToContain('Sent 1')->assertSuccessful();
    }

    // ---- the settings page's endpoints ----

    public function test_only_a_super_admin_can_use_the_sync_settings(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/quickbooks/sync')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/quickbooks/sync', [])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/quickbooks/accounts')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/quickbooks/accounts/create-missing')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/quickbooks/sync/run')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/quickbooks/sync/retry')->assertForbidden();
    }

    public function test_the_settings_page_lists_the_accounts_the_counts_and_what_was_sent(): void
    {
        $admin = $this->admin();
        $this->connect($admin);
        $this->enableSync();
        $lease = $this->lease('Jane Doe');
        $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-14');
        $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-15');
        $this->runSync();
        $this->paid($lease, Payment::TYPE_RENTAL, 216.50, '2026-10-16');

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/quickbooks/sync')->assertOk();

        $response->assertJsonPath('data.connected', true);
        $response->assertJsonPath('data.enabled', true);
        $response->assertJsonPath('data.ready', true);
        $response->assertJsonPath('data.sync_from', '2026-10-01');
        $response->assertJsonPath('data.counts', ['synced' => 2, 'waiting' => 1, 'failed' => 0, 'skipped' => 0]);
        $response->assertJsonCount(8, 'data.accounts');
        $response->assertJsonPath('data.recent.0.customer', 'Jane Doe');
        $response->assertJsonPath('data.recent.0.amount', 216.5);
        $this->assertNotNull($response->json('data.recent.0.entry_id'));
    }

    public function test_choosing_accounts_saves_them_with_the_names_quickbooks_gives_and_sending_can_then_be_switched_on(): void
    {
        $admin = $this->admin();
        $this->connect($admin);
        $keys = array_keys(QuickbooksSyncSetting::ACCOUNTS);
        $accounts = [];
        foreach ($keys as $i => $key) {
            $accounts[$key] = (string) (101 + $i);
        }

        $response = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/quickbooks/sync', [
            'sync_enabled' => true,
            'sync_from' => '2026-10-15',
            'accounts' => $accounts,
        ])->assertOk();

        $response->assertJsonPath('data.enabled', true);
        $response->assertJsonPath('data.ready', true);
        $response->assertJsonPath('data.accounts.0.selected', ['id' => '101', 'name' => 'Account 1']);
        $this->assertTrue(QuickbooksSyncSetting::current()->sync_enabled);
        $this->assertSame('2026-10-15', QuickbooksSyncSetting::current()->sync_from->toDateString());
    }

    public function test_sending_cannot_be_switched_on_with_an_account_missing_or_no_start_day(): void
    {
        $admin = $this->admin();
        $this->connect($admin);

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/quickbooks/sync', [
            'sync_enabled' => true,
            'sync_from' => '2026-10-15',
            'accounts' => ['rent_income' => '101'],
        ])->assertStatus(422);

        $everything = [];
        foreach (array_keys(QuickbooksSyncSetting::ACCOUNTS) as $i => $key) {
            $everything[$key] = (string) (101 + $i);
        }
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/quickbooks/sync', [
            'sync_enabled' => true,
            'sync_from' => null,
            'accounts' => $everything,
        ])->assertStatus(422);

        $this->assertFalse(QuickbooksSyncSetting::current()->sync_enabled);
    }

    public function test_an_account_that_is_not_in_quickbooks_and_a_start_day_in_the_future_are_refused(): void
    {
        $admin = $this->admin();
        $this->connect($admin);

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/quickbooks/sync', [
            'sync_enabled' => false,
            'sync_from' => '2026-10-15',
            'accounts' => ['rent_income' => '99999'],
        ])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/quickbooks/sync', [
            'sync_enabled' => false,
            'sync_from' => '2026-12-31',
            'accounts' => [],
        ])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/quickbooks/sync', [
            'sync_enabled' => false,
            'sync_from' => '2026-10-15',
            'accounts' => ['made_up' => '101'],
        ])->assertStatus(422);
    }

    public function test_creating_the_missing_accounts_makes_only_what_is_missing_and_reuses_one_that_already_exists(): void
    {
        $admin = $this->admin();
        $this->connect($admin);
        $this->quickbooksAccounts[] = ['Id' => '900', 'Name' => 'Rental Income', 'FullyQualifiedName' => 'Rental Income', 'AccountType' => 'Income', 'Active' => true];
        QuickbooksSyncSetting::current()->update(['accounts' => ['late_fee_income' => ['id' => '105', 'name' => 'Account 5']]]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/quickbooks/accounts/create-missing')->assertOk();

        $response->assertJsonPath('data.missing', []);
        $saved = QuickbooksSyncSetting::current();
        $this->assertSame('105', $saved->accountId('late_fee_income'), 'An account already chosen is left alone.');
        $this->assertSame('900', $saved->accountId('rent_income'), 'An account of the same name is reused, not duplicated.');

        $created = collect($this->calls)
            ->filter(fn (Request $r) => $r->method() === 'POST' && preg_match('#/account\?#', $r->url()))
            ->map(fn (Request $r) => [$r['Name'], $r['AccountType']])
            ->values()->all();
        $this->assertContains(['Sales Tax Collected', 'Other Current Liability'], $created);
        $this->assertContains(['Payments Clearing', 'Other Current Asset'], $created);
        $this->assertContains(['Card Fees Collected', 'Income'], $created);
        $this->assertNotContains(['Rental Income', 'Income'], $created);
        $this->assertNotContains(['Late Fee Income', 'Income'], $created);
        $this->assertCount(6, $created);
    }

    public function test_send_now_sends_the_waiting_payments_and_reports_what_happened(): void
    {
        $admin = $this->admin();
        $this->connect($admin);
        $this->enableSync();
        $this->paid($this->lease(), Payment::TYPE_RENTAL, 216.50, '2026-10-15');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/quickbooks/sync/run')->assertOk();

        $response->assertJsonPath('result.sent', 1);
        $response->assertJsonPath('data.counts.synced', 1);
        $response->assertJsonPath('data.counts.waiting', 0);
        $response->assertJsonPath('data.last_error', null);
    }

    public function test_the_account_list_asks_quickbooks_and_a_dead_connection_is_reported_plainly(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/quickbooks/accounts')
            ->assertStatus(409)
            ->assertJsonPath('message', 'QuickBooks is not connected.');

        $this->connect($admin);
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/quickbooks/accounts')->assertOk();
        $response->assertJsonCount(12, 'data');
        $response->assertJsonPath('data.0.name', 'Account 1');
    }
}
