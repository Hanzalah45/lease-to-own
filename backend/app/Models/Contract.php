<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    use HasFactory;

    protected $fillable = [
        'lease_agreement_id',
        'signer_user_id',
        'signer_name',
        'file_path',
        'document_html',
        'version',
        'signed_at',
        'ip_address',
        'user_agent',
        'external_provider',
        'external_envelope_id',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    /** Audit-trail fields — kept in the database but never serialized to the API. */
    protected $hidden = ['ip_address', 'user_agent', 'document_html'];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function leaseAgreement(): BelongsTo
    {
        return $this->belongsTo(LeaseAgreement::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
