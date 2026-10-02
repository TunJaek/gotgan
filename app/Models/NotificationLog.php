<?php

namespace App\Models;

use App\Enums\ReviewDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    protected $primaryKey = 'idx';

    protected $fillable = [
        'type', 'recipient_user_idx', 'success',
        'provider_message_id', 'error_message', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'success' => 'boolean',
            'type' => ReviewDecision::class,
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_idx');
    }
}
