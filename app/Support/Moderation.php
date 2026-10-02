<?php

namespace App\Support;

use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 운영자 제재와 복구. 신고 화면과 대상 관리 화면이 같이 쓴다.
 *
 * 두 화면에서 같은 일을 하므로 실행 로직만 여기 모은다.
 * 범용 제재 시스템을 만들지는 않는다 — 지금 필요한 것은 숨김·정지와 그 되돌리기뿐이다.
 *
 * 모든 메서드가 '대상 상태 변경 + 신고 종결 + 로그'를 한 트랜잭션으로 묶는다.
 * 중간에 끊기면 글만 숨겨지고 신고는 미처리로 남아 같은 건을 또 보게 된다.
 *
 * 신고자가 쓴 사유는 reports.reason 에 그대로 두고,
 * 운영자가 쓴 처리 사유는 audit_logs.reason 에 남긴다. 둘은 다른 사람이 쓴 글이다.
 */
class Moderation
{
    /** 매물을 숨기고, 선택한 신고를 조치완료로 닫는다. */
    public static function hidePost(Post $post, ?string $reason = null, array $report_idxs = []): void
    {
        DB::transaction(function () use ($post, $reason, $report_idxs) {
            // 이미 숨겨져 있으면 상태를 건드리지 않는다. 로그도 남기지 않는다 —
            // 바뀐 게 없는데 조치 기록만 쌓이면 이력을 읽을 때 헷갈린다.
            if (! $post->is_hidden) {
                $post->update(['is_hidden' => true]);
                AuditLog::record('post.hidden', 'post', $post->idx, $reason);
            }

            self::closeReports($report_idxs, ReportStatus::Resolved, $reason);
        });
    }

    /** 매물 숨김을 푼다. is_hidden 만 바꾼다 — 판매자가 정한 status 는 그대로 둔다. */
    public static function unhidePost(Post $post, ?string $reason = null): void
    {
        // 탈퇴한 회원의 매물은 되살리지 않는다. 판매자가 '탈퇴한 회원'인 글이 장터에 뜨면 안 된다.
        if (! $post->is_hidden || $post->user->status === UserStatus::Withdrawn) {
            return;
        }

        DB::transaction(function () use ($post, $reason) {
            $post->update(['is_hidden' => false]);
            AuditLog::record('post.unhidden', 'post', $post->idx, $reason);
        });
    }

    /**
     * 회원을 정지하고, 그 회원의 매물도 함께 숨기고, 선택한 신고를 조치완료로 닫는다.
     *
     * 매물까지 숨기는 이유 — 명세서의 존속 점검(폐업 의심)이 해당 사업자의 매물을 전부
     * 미노출한다. 제재로 인한 정지도 같은 정책으로 맞춘다. 정지된 회원의 매물이 장터에
     * 그대로 남아 있으면 정지한 의미가 없다.
     *
     * 이때 숨긴 매물은 post.hidden.by_suspend 로 따로 적는다. 복구할 때 이 기록을 보고
     * 되살리므로, 판매자가 스스로 내려둔 매물까지 들춰내지 않는다.
     */
    public static function suspendUser(User $user, ?string $reason = null, array $report_idxs = []): void
    {
        DB::transaction(function () use ($user, $reason, $report_idxs) {
            // 탈퇴한 회원은 이미 매물이 숨겨지고 로그인도 막혔다. 정지로 상태를 덮어쓰지 않는다.
            if ($user->status === UserStatus::Active) {
                // 사유는 회원의 내 정보 화면에 그대로 보인다(조치 기록과 같은 글)
                $user->forceFill([
                    'status' => UserStatus::Suspended,
                    'suspended_reason' => $reason,
                    'remember_token' => Str::random(60),   // '로그인 상태 유지' 쿠키로 다시 들어오지 못하게
                ])->save();
                // 정지 확인은 로그인 화면에서만 한다. 이미 로그인해 있던 기기도 끊는다(탈퇴와 같은 처리)
                DB::table('sessions')->where('user_id', $user->idx)->delete();
                AuditLog::record('user.suspended', 'user', $user->idx, $reason);

                // 지금 노출 중인 것만 건드린다. 이미 숨겨진 매물은 그대로 둔다.
                foreach (Post::where('user_idx', $user->idx)->where('is_hidden', false)->get() as $post) {
                    $post->update(['is_hidden' => true]);
                    AuditLog::record('post.hidden.by_suspend', 'post', $post->idx, $reason);
                }
            }

            self::closeReports($report_idxs, ReportStatus::Resolved, $reason);
        });
    }

    /**
     * 회원 정지를 푼다. 정지 때문에 숨겨졌던 매물도 함께 되살린다.
     *
     * 되살리는 대상은 마지막 숨김 기록이 post.hidden.by_suspend 인 매물뿐이다.
     * 그 뒤에 운영자가 따로 숨겼거나(post.hidden) 판매자가 내린 것은 그대로 둔다.
     */
    public static function reinstateUser(User $user, ?string $reason = null): void
    {
        if ($user->status !== UserStatus::Suspended) {
            return;
        }

        DB::transaction(function () use ($user, $reason) {
            $user->forceFill(['status' => UserStatus::Active, 'suspended_reason' => null])->save();
            AuditLog::record('user.reinstated', 'user', $user->idx, $reason);

            foreach (Post::where('user_idx', $user->idx)->where('is_hidden', true)->get() as $post) {
                $last = AuditLog::where('target_type', 'post')
                    ->where('target_idx', $post->idx)
                    ->whereIn('action', ['post.hidden', 'post.hidden.by_suspend', 'post.unhidden'])
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('idx')
                    ->first();

                if ($last?->action !== 'post.hidden.by_suspend') {
                    continue;       // 다른 이유로 숨겨진 매물이다
                }

                $post->update(['is_hidden' => false]);
                AuditLog::record('post.unhidden.by_reinstate', 'post', $post->idx, $reason);
            }
        });
    }

    /** 대상은 그대로 두고 신고만 기각한다. */
    public static function dismissReports(array $report_idxs, ?string $reason = null): void
    {
        DB::transaction(fn () => self::closeReports($report_idxs, ReportStatus::Dismissed, $reason));
    }

    /**
     * 이미 숨김·정지된 대상이라 새로 할 조치가 없을 때, 신고만 닫는다.
     * 상태를 바꾸지 않으므로 대상 로그는 남기지 않는다.
     */
    public static function resolveReports(array $report_idxs, ?string $reason = null): void
    {
        DB::transaction(fn () => self::closeReports($report_idxs, ReportStatus::Resolved, $reason));
    }

    /**
     * 선택한 신고를 닫고 신고마다 로그를 남긴다.
     *
     * 관리자가 화면에서 본 신고만 닫는다. 처리하는 사이에 새로 들어온 신고는
     * 목록에 없었으므로 그대로 미처리로 남아 다음에 다시 보인다.
     * 이미 닫힌 신고는 건드리지 않는다 — 처리 시각이 덮어써지면 이력이 흐려진다.
     */
    private static function closeReports(array $report_idxs, ReportStatus $status, ?string $reason): void
    {
        if (empty($report_idxs)) {
            return;
        }

        $reports = Report::whereIn('idx', $report_idxs)
            ->where('status', ReportStatus::Received)
            ->get();

        foreach ($reports as $report) {
            $report->update(['status' => $status]);

            AuditLog::record(
                $status === ReportStatus::Resolved ? 'report.resolved' : 'report.dismissed',
                'report',
                $report->idx,
                $reason
            );
        }
    }
}
