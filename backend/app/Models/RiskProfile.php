<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'identity_verification_status',
        'employment_verification_status',
        'bank_verification_status',
        'background_check_status',
        'background_check_notes',
        'risk_score',
        'landlord_contact_required',
        'landlord_contact_reason',
    ];

    protected function casts(): array
    {
        return [
            'landlord_contact_required' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function redFlags(): HasMany
    {
        return $this->hasMany(RiskRedFlag::class);
    }
}
