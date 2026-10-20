<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row, always: how the client's QuickBooks books are fed (see the migration for why this is not on the connection row).
 */
class QuickbooksSyncSetting extends Model
{
    /** Each kind of money and the account it is booked to. The label is what a person sees; `type` is the QuickBooks account type used when the portal creates the account. */
    public const ACCOUNTS = [
        'deposit_account' => [
            'label' => 'Money received',
            'help' => 'Where every payment lands first. Use a clearing account and match the card and bank payouts against it.',
            'default_name' => 'Payments Clearing',
            'type' => 'Other Current Asset',
        ],
        'rent_income' => [
            'label' => 'Rent income',
            'help' => 'Monthly rent, without the LDW and the sales tax.',
            'default_name' => 'Rental Income',
            'type' => 'Income',
        ],
        'ldw_income' => [
            'label' => 'Loss damage waiver income',
            'help' => 'The loss damage waiver (LDW) inside each monthly payment, without the sales tax. Choose the same account as rent income if you do not want it apart.',
            'default_name' => 'Loss Damage Waiver Income',
            'type' => 'Income',
        ],
        'sales_tax' => [
            'label' => 'Sales tax payable',
            'help' => 'The sales tax inside each monthly payment, owed to the state.',
            'default_name' => 'Sales Tax Collected',
            'type' => 'Other Current Liability',
        ],
        'tracking_fee_income' => [
            'label' => 'Tracking fee income',
            'help' => 'The $150 tracking fee.',
            'default_name' => 'Tracking Fee Income',
            'type' => 'Income',
        ],
        'security_deposit' => [
            'label' => 'Security deposit income',
            'help' => 'The security deposit. The contract says it is non-refundable, so it is income when it is received.',
            'default_name' => 'Security Deposit Income',
            'type' => 'Income',
        ],
        'late_fee_income' => [
            'label' => 'Late fee income',
            'help' => 'Late fees that were paid.',
            'default_name' => 'Late Fee Income',
            'type' => 'Income',
        ],
        'card_fee_income' => [
            'label' => 'Card fees',
            'help' => 'The card fee customers pay on top of the bank price. It is credited to this expense account, so it offsets the card fees you pay.',
            'default_name' => 'Card Processing Fees',
            'type' => 'Expense',
        ],
        'extra_payment' => [
            'label' => 'Extra payments held',
            'help' => 'Extra payments toward the early purchase price, a liability until applied.',
            'default_name' => 'Customer Extra Payments Held',
            'type' => 'Other Current Liability',
        ],
        'repair_income' => [
            'label' => 'Repair costs recovered',
            'help' => 'Extra payments the customer made that were applied to the cost of repairs when the equipment came back (contract Section 19). Moved here out of the extra payments held.',
            'default_name' => 'Repair Cost Recovery Income',
            'type' => 'Income',
        ],
    ];

    protected $fillable = [
        'sync_enabled',
        'sync_from',
        'accounts',
        'last_synced_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'sync_enabled' => 'boolean',
            'sync_from' => 'date',
            'accounts' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->first() ?? self::create(['sync_enabled' => false, 'accounts' => []]);
    }

    /** The QuickBooks account id for a kind of money, or null when none is chosen. */
    public function accountId(string $key): ?string
    {
        $id = $this->accounts[$key]['id'] ?? null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    /** @return list<string> the kinds of money that still have no account */
    public function missingAccounts(): array
    {
        return array_values(array_filter(array_keys(self::ACCOUNTS), fn (string $key) => $this->accountId($key) === null));
    }

    /** Sending is on, there is a start day, and every kind of money has an account. */
    public function isReady(): bool
    {
        return $this->sync_enabled && $this->sync_from !== null && $this->missingAccounts() === [];
    }
}
