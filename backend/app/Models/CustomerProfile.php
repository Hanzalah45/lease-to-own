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
        'previous_address',
        'landlord_name',
        'landlord_phone',
        'monthly_rent',
        'mortgage_amount',
        'mortgage_years',
        'alternate_contact_1_name',
        'alternate_contact_1_phone',
        'alternate_contact_2_name',
        'alternate_contact_2_phone',
        'move_notification_agreed',
        'internal_notes',
        'employment_status',
        'employer_name',
        'employer_phone',
        'employer_position',
        'monthly_income',
        'utility_bill_document_path',
        'plaid_item_id',
        'plaid_access_token',
        'bank_verified_at',
        'payment_reminder_emails',
        'status_change_emails',
        'updated_by',
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
            'monthly_rent' => 'decimal:2',
            'mortgage_amount' => 'decimal:2',
            'payment_reminder_emails' => 'boolean',
            'status_change_emails' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isApartment(): bool
    {
        return $this->residence_type === 'apartment';
    }
}
