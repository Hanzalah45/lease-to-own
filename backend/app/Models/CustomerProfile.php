<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'government_id_type',
        'government_id_number',
        'government_id_document_path',
        'identity_verified_at',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'zip',
        'date_of_birth',
        'residence_type',
        'years_at_residence',
        'landlord_name',
        'landlord_phone',
        'move_notification_agreed',
        'internal_notes',
        'employment_status',
        'employer_name',
        'employer_phone',
        'monthly_income',
        'plaid_item_id',
        'plaid_access_token',
        'bank_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'identity_verified_at' => 'datetime',
            'bank_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'move_notification_agreed' => 'boolean',
            'plaid_access_token' => 'encrypted',
            'monthly_income' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isApartment(): bool
    {
        return $this->residence_type === 'apartment';
    }
}
