<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\QuickbooksConnection;
use App\Models\QuickbooksSyncSetting;
use App\Services\BillingClock;
use App\Services\QuickbooksClient;
use App\Services\QuickbooksException;
use App\Services\QuickbooksSyncer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The QuickBooks sync's settings page: which accounts each kind of money is booked to, the start day, the on/off switch, and
 * what has been sent. Super admin only, like the connection itself.
 */
class QuickbooksSyncController extends Controller
{
    public function __construct(
        private readonly QuickbooksClient $client,
        private readonly QuickbooksSyncer $syncer,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    /** The company's active accounts, for the pickers. */
    public function accounts(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->client->accounts()]);
        } catch (QuickbooksException $e) {
            return $this->failure($e);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $keys = array_keys(QuickbooksSyncSetting::ACCOUNTS);
        $data = $request->validate([
            'sync_enabled' => ['required', 'boolean'],
            'sync_from' => ['nullable', 'date_format:Y-m-d'],
            'accounts' => ['present', 'array'],
            'accounts.*' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
        ]);
        if (array_diff(array_keys($data['accounts']), $keys) !== []) {
            return response()->json(['message' => 'Unknown kind of money.', 'errors' => ['accounts' => ['Unknown kind of money.']]], 422);
        }
        if (! empty($data['sync_from']) && $data['sync_from'] > BillingClock::todayDate()) {
            return response()->json(['message' => 'The start day cannot be in the future.', 'errors' => ['sync_from' => ['The start day cannot be in the future.']]], 422);
        }

        $chosen = array_filter($data['accounts'], fn ($id) => $id !== null && $id !== '');
        $accounts = [];
        if ($chosen !== []) {
            try {
                // The names come from QuickBooks itself, and an id that is not one of its active accounts is refused.
                $known = collect($this->client->accounts())->keyBy('id');
            } catch (QuickbooksException $e) {
                return $this->failure($e);
            }
            foreach ($chosen as $key => $id) {
                if (! $known->has($id)) {
                    return response()->json(['message' => 'One of the chosen accounts is not an active QuickBooks account.', 'errors' => ["accounts.{$key}" => ['Choose an active QuickBooks account.']]], 422);
                }
                $accounts[$key] = ['id' => $id, 'name' => $known[$id]['name']];
            }
        }

        $settings = QuickbooksSyncSetting::current();
        $settings->fill(['sync_from' => $data['sync_from'] ?? null, 'accounts' => $accounts, 'sync_enabled' => false]);

        if ($data['sync_enabled']) {
            if (! QuickbooksConnection::first()) {
                return response()->json(['message' => 'Connect QuickBooks first.', 'errors' => ['sync_enabled' => ['Connect QuickBooks first.']]], 422);
            }
            $settings->sync_enabled = true;
            if (! $settings->sync_from || $settings->missingAccounts() !== []) {
                return response()->json(['message' => 'Choose a start day and an account for every kind of money before switching sending on.', 'errors' => ['sync_enabled' => ['Choose a start day and an account for every kind of money first.']]], 422);
            }
        }
        $settings->save();

        return response()->json(['data' => $this->payload()]);
    }

    /**
     * Creates, in the client's QuickBooks, the accounts that have no choice yet (reusing one of the same name when it is
     * already there) and chooses them.
     */
    public function createAccounts(): JsonResponse
    {
        $settings = QuickbooksSyncSetting::current();
        $accounts = $settings->accounts ?? [];

        try {
            foreach ($settings->missingAccounts() as $key) {
                $spec = QuickbooksSyncSetting::ACCOUNTS[$key];
                $found = $this->client->query('Account', "select * from Account where Name = '{$spec['default_name']}'");
                $existing = collect($found)->first(fn (array $row) => ($row['Active'] ?? true) !== false);
                $account = $existing ?? $this->client->create('Account', ['Name' => $spec['default_name'], 'AccountType' => $spec['type']], "pl-account-{$key}");

                $accounts[$key] = ['id' => (string) $account['Id'], 'name' => (string) ($account['FullyQualifiedName'] ?? $account['Name'] ?? $spec['default_name'])];
            }
        } catch (QuickbooksException $e) {
            // What was made before the failure is kept, so a second press carries on instead of starting over.
            $settings->update(['accounts' => $accounts]);

            return $this->failure($e);
        }

        $settings->update(['accounts' => $accounts]);

        return response()->json(['data' => $this->payload()]);
    }

    /** "Send now": the same run the scheduler does every five minutes. */
    public function run(): JsonResponse
    {
        $result = $this->syncer->run(50);

        return response()->json(['data' => $this->payload(), 'result' => $result]);
    }

    /** "Try again": payments QuickBooks refused go back in line and are sent. */
    public function retry(): JsonResponse
    {
        $this->syncer->retryFailed();
        $result = $this->syncer->run(50);

        return response()->json(['data' => $this->payload(), 'result' => $result]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $settings = QuickbooksSyncSetting::current();
        $connection = QuickbooksConnection::first();
        $waiting = $settings->sync_from
            ? Payment::where('status', Payment::STATUS_PAID)->whereNull('quickbooks_sync_status')->whereDate('paid_date', '>=', $settings->sync_from->toDateString())->count()
            : 0;

        $accounts = [];
        foreach (QuickbooksSyncSetting::ACCOUNTS as $key => $spec) {
            $accounts[] = [
                'key' => $key,
                'label' => $spec['label'],
                'help' => $spec['help'],
                'default_name' => $spec['default_name'],
                'type' => $spec['type'],
                'selected' => $settings->accounts[$key] ?? null,
            ];
        }

        return [
            'connected' => $connection !== null && ! $connection->isRefreshTokenExpired(),
            'enabled' => $settings->sync_enabled,
            'sync_from' => $settings->sync_from?->toDateString(),
            // The oldest payment on record, for the "include the payments so far" choice.
            'earliest_paid_date' => ($earliest = Payment::where('status', Payment::STATUS_PAID)->min('paid_date')) ? substr((string) $earliest, 0, 10) : null,
            'ready' => $settings->isReady(),
            'missing' => $settings->missingAccounts(),
            'accounts' => $accounts,
            'counts' => [
                'synced' => Payment::where('quickbooks_sync_status', QuickbooksSyncer::STATUS_SYNCED)->count(),
                'waiting' => $waiting,
                'failed' => Payment::where('quickbooks_sync_status', QuickbooksSyncer::STATUS_FAILED)->count(),
                'skipped' => Payment::where('quickbooks_sync_status', QuickbooksSyncer::STATUS_SKIPPED)->count(),
            ],
            'last_synced_at' => $settings->last_synced_at,
            'last_error' => $settings->last_error,
            'recent' => $this->rows(Payment::where('quickbooks_sync_status', QuickbooksSyncer::STATUS_SYNCED)->orderByDesc('quickbooks_synced_at')->orderByDesc('id')->limit(10)),
            'failed' => $this->rows(Payment::where('quickbooks_sync_status', QuickbooksSyncer::STATUS_FAILED)->orderByDesc('id')->limit(20)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows($query): array
    {
        return $query->with('leaseAgreement.customer:id,name')->get()->map(fn (Payment $p) => [
            'payment_id' => $p->id,
            'lease_id' => $p->lease_agreement_id,
            'customer' => $p->leaseAgreement?->customer?->name,
            'type' => $p->type,
            'paid_date' => $p->paid_date?->toDateString(),
            'amount' => $p->totalCharged(),
            'entry_id' => $p->quickbooks_record_id,
            'synced_at' => $p->quickbooks_synced_at,
            'error' => $p->quickbooks_sync_error,
        ])->all();
    }

    private function failure(QuickbooksException $e): JsonResponse
    {
        $status = $e->authFailed ? 409 : ($e->transient ? 503 : 422);

        return response()->json(['message' => $e->getMessage()], $status);
    }
}
