<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    use HasFactory;

    /**
     * The wording of the agreement new signatures are given on. 2: the early purchase price (Section 3) deducts any additional funds,
     * as the Technical Project Plan says. 1: the original wording, which every contract signed before the change keeps. Keep
     * CURRENT_CONTRACT_TERMS_VERSION in the frontend (LeaseAgreementDocument) in step with this.
     */
    public const CURRENT_TERMS_VERSION = 2;

    /** First wording version that deducts additional funds from the early purchase price. */
    public const TERMS_DEDUCT_ADDITIONAL_FUNDS = 2;

    /** First wording version of Section 19 that applies additional funds to repairs before any refund (client, Joel, 2026-10-20). */
    public const TERMS_REPAIRS_BEFORE_REFUND = 2;

    protected $fillable = [
        'lease_agreement_id',
        'signer_user_id',
        'signer_name',
        'file_path',
        'document_html',
        'version',
        'terms_version',
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

    /** Whether the Section 19 this contract was signed with applies Additional Funds to repairs before anything is refunded. */
    public function appliesFundsToRepairsFirst(): bool
    {
        return ($this->terms_version ?? self::CURRENT_TERMS_VERSION) >= self::TERMS_REPAIRS_BEFORE_REFUND;
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
