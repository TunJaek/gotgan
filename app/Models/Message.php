<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 문의 대화의 메시지 한 줄. 테이블은 messages.
 *
 * post_idx 와 terms_snapshot 은 비어 있을 수 있다.
 * 매물 상세에서 '문의하기' 로 보낸 첫 메시지에만 붙고, 그 뒤 주고받는 말에는 붙지 않는다.
 * 매물이 삭제되면 post_idx 만 NULL 이 되고 메시지 자체는 남는다(외래키가 nullOnDelete).
 */
class Message extends Model
{
    protected $primaryKey = 'idx';

    protected $fillable = [
        'inquiry_idx', 'sender_idx', 'post_idx', 'terms_snapshot', 'body', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'terms_snapshot' => 'array',   // JSON 컬럼. ['price' => …, 'vat' => …, 'status' => …]
            'read_at' => 'datetime',
        ];
    }

    /** 이 메시지가 속한 대화방. */
    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_idx');
    }

    /** 보낸 사람. */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_idx');
    }

    /** 붙어 있는 매물. 없으면 null. */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_idx');
    }

    /** 문의 시점의 가격·조건을 박아둔다. 나중에 글이 바뀌어도 대화 맥락이 남는다. */
    public static function snapshotOf(Post $post): array
    {
        return [
            'price' => $post->price_amount,
            'vat' => $post->vat_mode?->label(),
            'status' => $post->status->name,
        ];
    }

    /**
     * 도배 막기. 대화방과 첫 문의가 같이 센다. 회원당 1분에 20건.
     * 넘었으면 안내 문구를, 괜찮으면 null 을 돌려주고 한 건을 센다.
     */
    public static function tooFast(int $user_idx): ?string
    {
        $key = 'message:'.$user_idx;

        if (RateLimiter::tooManyAttempts($key, 20)) {
            return '메시지를 너무 빨리 보내고 있습니다. '.RateLimiter::availableIn($key).'초 뒤에 다시 보내 주세요.';
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}
