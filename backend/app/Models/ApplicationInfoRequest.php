<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One "needs info" round — the ask, and (once answered) the reply. Never overwritten; a new round is a new row. */
class ApplicationInfoRequest extends Model
{
    protected $fillable = [
        'application_id',
        'requested_by_user_id',
        'request_text',
        'replied_at',
        'reply_text',
        'reply_document_path',
    ];

    protected function casts(): array
    {
        return [
            'replied_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
