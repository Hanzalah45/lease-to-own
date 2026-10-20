<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer's agreement to AutoPay on one lease, given with the tick box in the portal. Kept, never edited: see the migration.
 */
class AutopayAgreement extends Model
{
    protected $fillable = [
        'lease_agreement_id',
        'user_id',
        'wording_version',
        'wording',
        'authorization',
        'via',
        'ip_address',
        'user_agent',
        'agreed_at',
    ];

    /** Audit trail kept in the database and not sent to the API; the staff screens only need who and when. */
    protected $hidden = ['wording', 'authorization', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return [
            'authorization' => 'array',
            'agreed_at' => 'datetime',
        ];
    }

    public function leaseAgreement(): BelongsTo
    {
        return $this->belongsTo(LeaseAgreement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
