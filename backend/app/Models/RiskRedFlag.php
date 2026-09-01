<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskRedFlag extends Model
{
    public const TYPE_MISSED_PAYMENT = 'missed_payment';
    public const TYPE_LATE_PAYMENT = 'late_payment';
    public const TYPE_FAILED_ACH = 'failed_ach';
    public const TYPE_UNREACHABLE_CUSTOMER = 'unreachable_customer';
    public const TYPE_BANK_ACCOUNT_CHANGE = 'bank_account_change';
    public const TYPE_SUSPICIOUS_BEHAVIOR = 'suspicious_behavior';
    public const TYPE_UNDISCLOSED_MOVE = 'undisclosed_move';
    public const TYPE_GPS_ANOMALY = 'gps_anomaly';

    protected $fillable = [
        'risk_profile_id',
        'type',
        'description',
        'flagged_at',
        'resolved',
    ];

    protected function casts(): array
    {
        return [
            'flagged_at' => 'datetime',
            'resolved' => 'boolean',
        ];
    }

    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class);
    }
}
