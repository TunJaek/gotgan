<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 신고 한 건. 테이블은 reports.
 *
 * 신고는 '건별'로 남기고, 화면과 처리는 '대상별'로 묶는다.
 * 같은 매물에 신고가 다섯 건 쌓여도 관리자는 한 번 조치하고 그 다섯 건을 함께 닫는다.
 * 묶음을 저장하는 테이블은 두지 않는다 — (target_type, target_idx) 로 모으면 된다.
 *
 * target_type 에 따라 대상 테이블이 달라서 외래키가 없다. 대상은 loadTarget() 으로 꺼낸다.
 */
class Report extends Model
{
    protected $primaryKey = 'idx';

    protected $fillable = ['reporter_idx', 'target_type', 'target_idx', 'reason', 'status'];

    protected function casts(): array
    {
        return [
            'target_type' => ReportTarget::class,
            'status' => ReportStatus::class,
        ];
    }

    /** 신고한 사람. */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_idx');
    }

    /**
     * 기각된 뒤 같은 사람이 같은 대상을 다시 신고할 수 있게 되기까지의 날수.
     *
     * 영구 차단은 두지 않는다. 지금은 문제가 없었어도 나중에 진짜 문제가 생길 수 있고,
     * 그때 신고할 길이 막히면 안 된다.
     */
    public const REDO_DAYS = 3;

    /** 아직 처리하지 않은 신고. */
    public function scopeUnresolved(Builder $query): void
    {
        $query->where('status', ReportStatus::Received);
    }

    /**
     * 지금 이 사람이 이 대상을 신고할 수 있나. 막아야 하면 그 이유를, 괜찮으면 null.
     *
     * 반복 신고는 받아두고 관리자가 거르는 대신 접수 단계에서 막는다. 운영자가 한 명이라
     * 받아놓으면 그 부담이 고스란히 남는다.
     *
     * 사유 글귀를 비교하지는 않는다. 글자만 바꾸면 그냥 통과되므로 의미가 없다.
     * 막는 기준은 '누가 · 어느 대상을 · 언제' 뿐이다.
     *
     * 조치완료(Resolved)된 뒤의 재신고는 막지 않는다. 그때와 다른 문제일 수 있다.
     */
    public static function blockReason(?int $reporter_idx, ReportTarget $type, int $target_idx): ?string
    {
        if (! $reporter_idx) {
            return null;
        }

        $mine = fn () => static::query()
            ->where('reporter_idx', $reporter_idx)
            ->where('target_type', $type)
            ->where('target_idx', $target_idx);

        if ($mine()->where('status', ReportStatus::Received)->exists()) {
            return '이미 접수하신 신고를 검토하고 있습니다. 결과가 나온 뒤에 다시 신고해 주세요.';
        }

        $dismissed = $mine()->where('status', ReportStatus::Dismissed)
            ->orderByDesc('updated_at')
            ->first();

        if ($dismissed && $dismissed->updated_at->gt(now()->subDays(self::REDO_DAYS))) {
            $until = $dismissed->updated_at->copy()->addDays(self::REDO_DAYS);

            return '최근 신고가 검토 후 기각되었습니다. '
                .$until->timezone('Asia/Seoul')->format('n월 j일 H시')
                .' 이후에 다시 신고하실 수 있습니다.';
        }

        return null;
    }

    /** 신고 대상 한 건. 대상이 지워졌으면 null. */
    public function target(): Post|User|Message|null
    {
        return match ($this->target_type) {
            ReportTarget::Post => Post::find($this->target_idx),
            ReportTarget::User => User::find($this->target_idx),
            ReportTarget::Message => Message::find($this->target_idx),
            default => null,
        };
    }
}
