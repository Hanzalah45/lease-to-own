<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row = one area a specific admin is *restricted to*. An admin with no
 * rows here has full access to everything; adding rows narrows them down to
 * just those areas. See User::hasAdminPermission().
 */
class AdminPermission extends Model
{
    public const APPLICATION_REVIEW = 'application_review';
    public const RISK_ASSESSMENT = 'risk_assessment';
    public const CONTRACT_GENERATION = 'contract_generation';
    public const EQUIPMENT_TRACKING = 'equipment_tracking';
    public const PAYMENT_TRACKING = 'payment_tracking';

    protected $fillable = [
        'user_id',
        'permission',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
