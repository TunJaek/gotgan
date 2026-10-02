<?php

namespace App\Models;

use App\Enums\PostCondition;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\VatMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 매물 글. 테이블은 posts.
 *
 * 컬럼이 뭐가 있는지는 두 군데서 볼 수 있다.
 *   1) database/migrations/2026_09_16_000200_create_post_tables.php
 *   2) Workbench 에서 posts 테이블 → 각 컬럼의 Comment
 */
class Post extends Model
{
    protected $primaryKey = 'idx';

    // create()/update() 로 저장을 허용하는 컬럼 목록. 빠진 컬럼은 에러 없이 조용히 무시된다.
    protected $fillable = [
        'user_idx', 'post_type', 'category_id', 'title', 'description', 'condition', 'status',
        'is_hidden', 'region_code', 'region_name', 'show_phone', 'price_amount', 'price_negotiable', 'vat_mode', 'budget_amount',
        'listed_until', 'photos',
    ];

    /**
     * 장터의 지역 선택지 캐시 이름(뒤에 post_type 을 붙인다). 선택지는 올라온 글 전체를 지역별로 묶어야 해서
     * 매번 계산하면 무겁다. 10분 캐시하고, 글이 저장·삭제되면 바로 비운다(2026-09-29).
     */
    public const REGIONS_CACHE = 'market.regions.';

    protected static function booted(): void
    {
        $forget = function () {
            foreach (PostType::cases() as $type) {
                \Illuminate\Support\Facades\Cache::forget(self::REGIONS_CACHE.$type->value);
            }
        };
        static::saved($forget);
        static::deleted($forget);
    }

    // DB 에서 꺼낼 때 바꿀 타입. 안 적으면 '0' 같은 문자열 그대로 들어온다.
    protected function casts(): array
    {
        return [
            'post_type' => PostType::class,
            'status' => PostStatus::class,
            'condition' => PostCondition::class,
            'vat_mode' => VatMode::class,
            'is_hidden' => 'boolean',
            'show_phone' => 'boolean',
            'price_negotiable' => 'boolean',
            'listed_until' => 'date',
            'photos' => 'array',   // JSON 컬럼. 첫 번째가 대표 사진
        ];
    }

    /** 카테고리. posts.category_id → categories.idx */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /** 이 글을 쓴 회원. posts.user_idx → users.idx */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 사진 주소. 뒤에 붙는 v 값은 저장경로에서 뽑은 것이라
     * 사진을 바꾸면 주소가 같이 바뀐다 → 브라우저가 새로 받는다.
     */
    public function photoUrl(int $i): string
    {
        $key = $this->photos[$i]['key'] ?? '';

        return route('images.show', [$this, $i]).'?v='.substr(md5($key), 0, 8);
    }

    /** 대표 사진. 사진이 없으면 null. */
    public function coverPhoto(): ?array
    {
        return $this->photos[0] ?? null;
    }

    /** 판매중(모집중)일 때만 새 문의·연락처 보기를 받는다. 거래완료·중지된 글은 보기만 된다. */
    public function isOpenForDeal(): bool
    {
        return $this->status === PostStatus::Selling;
    }

    /** 게시 마감일이 지났는가. 마감일 당일까지는 보인다. */
    public function isExpired(): bool
    {
        return $this->listed_until !== null && $this->listed_until->lt(today());
    }

    /** 목록에 내보낼 글만 남긴다. 마감일이 없으면 계속 노출한다. */
    public function scopeStillListed(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereNull('listed_until')->orWhereDate('listed_until', '>=', today());
        });
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $post = $this->where('idx', $value)
            ->where('is_hidden', false)
            ->where('status', '!=', PostStatus::Draft)
            ->firstOrFail();

        // 마감일이 지난 글은 글쓴이만 볼 수 있다.
        // 403 이 아니라 404 로 막는다 — 있는지 없는지도 알려줄 이유가 없다.
        abort_if(
            $post->isExpired() && $post->user_idx !== auth()->id(),
            404
        );

        return $post;
    }
}
