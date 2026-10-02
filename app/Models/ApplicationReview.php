<?php

namespace App\Models;

use App\Enums\ReviewDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationReview extends Model
{
    protected $primaryKey = 'idx';

    protected $fillable = ['user_idx', 'reviewer_idx', 'decision', 'reason', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'decision' => ReviewDecision::class,
        ];
    }

    /** 심사 대상 회원. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_idx');
    }

    /** 심사한 운영자. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_idx');
    }
}
