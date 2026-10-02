<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * 문의 대화방. 테이블은 inquiries.
 *
 * 방은 '사람 한 쌍'에 하나다. 같은 사람에게 다른 매물을 문의해도 같은 방을 쓴다.
 * 어느 매물 얘기인지는 방이 아니라 메시지가 들고 있다(messages.post_idx).
 *
 * from_user_idx 는 '먼저 말을 건 사람', to_user_idx 는 '상대'다.
 * 판매자·구매자를 뜻하지 않는다 — 한 방에서 둘 다 문의를 걸 수 있다.
 */
class Inquiry extends Model
{
    protected $primaryKey = 'idx';

    protected $fillable = ['from_user_idx', 'to_user_idx', 'status', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'status' => InquiryStatus::class,
        ];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_idx');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_idx');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'inquiry_idx');
    }

    /** 목록에 보여줄 마지막 메시지 1건. */
    public function lastMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'inquiry_idx')->latestOfMany('idx');
    }

    /**
     * 매물이 붙은 메시지 중 가장 최근 것.
     * 이 방이 '받은 문의'인지 '보낸 문의'인지는 이 매물의 주인으로 가른다.
     */
    public function lastPostMessage(): HasOne
    {
        // 조건을 ofMany 의 두 번째 인자로 넘겨야 안쪽 집계 쿼리에도 같이 걸린다.
        // ->whereNotNull(...)->latestOfMany() 로 쓰면 바깥 쿼리에만 걸려서 늘 NULL 이 나온다.
        return $this->hasOne(Message::class, 'inquiry_idx')->ofMany(
            ['idx' => 'max'],
            fn ($query) => $query->whereNotNull('post_idx')
        );
    }

    /** 내가 낀 방만 남긴다. */
    public function scopeForUser(Builder $query, int $user_idx): void
    {
        $query->where(function (Builder $q) use ($user_idx) {
            $q->where('from_user_idx', $user_idx)->orWhere('to_user_idx', $user_idx);
        });
    }

    /** 상대. 내가 from 이면 to 를, 내가 to 면 from 을 준다. */
    public function getPartnerAttribute(): ?User
    {
        return $this->from_user_idx === auth()->id() ? $this->to : $this->from;
    }

    /** 이 방의 마지막 매물. 없으면 null. */
    public function getLastPostAttribute(): ?Post
    {
        return $this->lastPostMessage?->post;
    }

    /**
     * 두 사람의 방을 찾고, 없으면 만든다.
     *
     * 방을 찾는 조건은 반드시 여기 한 곳에만 둔다.
     * (A,B) 와 (B,A) 를 양쪽으로 찾아야 하는데, 이 조건이 여러 군데로 복사되면
     * 한쪽만 고쳐서 같은 두 사람의 대화가 두 방으로 갈라진다.
     *
     * @param  User  $opener  지금 말을 거는 사람
     */
    /** 두 사람의 방을 찾기만 한다. 없으면 null. 방을 만들면 안 되는 곳(관리자 화면)에서 쓴다. */
    public static function findBetween(int $a, int $b): ?self
    {
        return static::query()
            ->where(function (Builder $q) use ($a, $b) {
                $q->where('from_user_idx', $a)->where('to_user_idx', $b);
            })
            ->orWhere(function (Builder $q) use ($a, $b) {
                $q->where('from_user_idx', $b)->where('to_user_idx', $a);
            })
            ->first();
    }

    public static function between(User $opener, User $other): self
    {
        abort_if($opener->idx === $other->idx, 403, '자기 자신과는 대화할 수 없습니다.');

        $found = static::findBetween($opener->idx, $other->idx);

        if ($found) {
            return $found;
        }

        try {
            return static::create([
                'from_user_idx' => $opener->idx,
                'to_user_idx' => $other->idx,
                'status' => InquiryStatus::Open,
            ]);
        } catch (UniqueConstraintViolationException) {
            // 두 요청이 같은 순간에 방을 만들려 한 경우. 먼저 만들어진 것을 쓴다.
            return static::between($opener, $other);
        }
    }
}
