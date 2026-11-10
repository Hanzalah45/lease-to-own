<?php

namespace App\Services;

/**
 * Says in plain words why a charge did not go through, for the email to the customer (client, Joel, 2026-11-02: his own insurance
 * company's failed-payment email told him there was a problem right away). Stripe's failure codes are turned into a sentence a
 * customer can act on; a code that is not known says nothing rather than something wrong.
 */
class PaymentFailureReason
{
    /** @var array<string, string> */
    private const REASONS = [
        'insufficient_funds' => 'There was not enough money in the account.',
        'account_closed' => 'The bank account is closed.',
        'no_account' => 'The bank could not find that account.',
        'invalid_account_number' => 'The bank account number is not valid.',
        'bank_account_restricted' => 'The bank account cannot be debited, because it is restricted.',
        'debit_not_authorized' => 'The bank account does not allow this debit.',
        'card_declined' => 'The card was declined.',
        'generic_decline' => 'The card was declined.',
        'do_not_honor' => 'The card was declined.',
        'lost_card' => 'The card was declined.',
        'stolen_card' => 'The card was declined.',
        'expired_card' => 'The card has expired.',
        'incorrect_cvc' => 'The card security code was not accepted.',
        'incorrect_number' => 'The card number was not accepted.',
        'processing_error' => 'The card company could not process the payment.',
    ];

    /** The sentence for a failure code, or null when the reason is not known. */
    public static function describe(?string $code): ?string
    {
        return $code === null ? null : (self::REASONS[$code] ?? null);
    }
}
