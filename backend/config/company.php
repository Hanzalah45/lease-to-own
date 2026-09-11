<?php

/**
 * Client, 2026-09-11: Joel doesn't have a business phone number set up yet
 * and wants the contract's arbitration clause ready to show it (and the
 * mailing address on file) once he does, without editing the contract
 * template again. Leave both blank in .env until then — the contract
 * renders the same as today when they're empty.
 */
return [
    'phone' => env('COMPANY_PHONE'),
    'address' => env('COMPANY_ADDRESS'),
];
