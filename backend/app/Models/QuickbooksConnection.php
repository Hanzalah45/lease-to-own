<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row, always — this app has exactly one QuickBooks company (the
 * client's business), not one per customer. See the connections table
 * migration for why this lives in the database instead of .env.
 */
class QuickbooksConnection extends Model
{
    protected $fillable = [
        'realm_id',
        'access_token',
        'access_token_expires_at',
        'refresh_token',
        'refresh_token_expires_at',
        'connected_by',
    ];

    protected function casts(): array
    {
        return [
            // Long-lived OAuth secrets for a third-party accounting system —
            // encrypted at rest the same way plaid_access_token is on
            // CustomerProfile, since a database dump would otherwise hand
            // over live QuickBooks access.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function isAccessTokenExpired(): bool
    {
        return $this->access_token_expires_at->isPast();
    }

    public function isRefreshTokenExpired(): bool
    {
        return $this->refresh_token_expires_at->isPast();
    }
}
