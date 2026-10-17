<?php

namespace App\Services;

use RuntimeException;

/**
 * Something went wrong talking to QuickBooks. The three kinds need different handling by the caller:
 * - transient: Intuit could not be reached or asked us to slow down; nothing is wrong with the data, try again later;
 * - authFailed: the connection itself is no longer good (not connected, revoked, refresh token expired); a person must reconnect;
 * - neither: QuickBooks understood the request and refused it (a closed period, an inactive account); the message says why.
 */
class QuickbooksException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false, public readonly bool $authFailed = false)
    {
        parent::__construct($message);
    }
}
