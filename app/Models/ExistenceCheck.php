<?php

namespace App\Models;

use App\Enums\ExistenceCheckResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExistenceCheck extends Model
{
    protected $primaryKey = 'idx';

    protected $fillable = [
        'user_idx', 'checked_at', 'result', 'raw_status_code', 'action_taken',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'result' => ExistenceCheckResult::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_idx');
    }
}
