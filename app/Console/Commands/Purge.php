<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Enums\ReportStatus;
use App\Enums\UserStatus;
use App\Models\ApplicationReview;
use App\Models\AuditLog;
use App\Models\ContactConsent;
use App\Models\ExistenceCheck;
use App\Models\Inquiry;
use App\Models\NotificationLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 보유기간이 지난 기록을 지운다. 개인정보처리방침 3장의 기간이 여기 숫자다(2026-09-28 결정).
 * 기간을 바꾸면 resources/legal/privacy-v*.md 도 같이 고친다.
 *
 * routes/console.php 가 매일 04:00 에 부른다. 서버 cron 에 schedule:run 이 걸려 있어야 돈다.
 * --dry-run 은 지우지 않고 몇 건이 지워질지만 보여 준다.
 */
#[Signature('app:purge {--dry-run : 지우지 않고 건수만 본다}')]
#[Description('보유기간이 지난 기록을 지웁니다.')]
class Purge extends Command
{
    /** 대부분의 기록 */
    public const KEEP_DAYS = 90;

    /** 대화와 운영자 조치 기록. 조치 기록은 개인정보 접속기록 1년 보관 기준 때문이다. */
    public const KEEP_DAYS_LONG = 365;

    private bool $dry = false;

    /** @var array<string, int> */
    private array $counts = [];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $cut = now()->subDays(self::KEEP_DAYS);
        $cut_long = now()->subDays(self::KEEP_DAYS_LONG);

        // 순서가 있다. 반려 신청을 먼저 치운 뒤에 심사 기록을 지운다 — 거꾸로면 '언제 반려됐는지'를 잃는다.

        // 1. 반려 신청: 마지막 심사가 90일 전이면 개인정보를 지운다(탈퇴와 같은 처리)
        $rejected = User::where('review_status', ApplicationStatus::Rejected)
            ->where('status', '!=', UserStatus::Withdrawn)
            ->whereDoesntHave('reviews', fn ($q) => $q->where('reviewed_at', '>=', $cut))
            // 심사 기록이 없는 행(샘플 데이터 등)도 방금 반려된 것으로 오해하지 않게, 회원 정보가 90일간 안 바뀐 것만
            ->where('updated_at', '<', $cut)
            ->get();
        $this->step('반려 신청 개인정보', $rejected->count(),
            fn () => $rejected->each(fn (User $u) => $u->withdraw('user.purged')));

        // 2. 사업자등록증 원본: 승인 후 90일. 심사중·보완 중인 것은 건드리지 않는다
        $licensed = User::where('review_status', ApplicationStatus::Approved)
            ->where('approved_at', '<', $cut)
            ->get()
            ->filter(fn (User $u) => Storage::disk('local')->exists($u->licenseKey()));
        $this->step('사업자등록증 원본', $licensed->count(),
            fn () => $licensed->each(fn (User $u) => Storage::disk('local')->delete($u->licenseKey())));

        // 3. 탈퇴 회원의 매물: 탈퇴 후 90일. 매물에 딸린 동의·열람 기록이 FK 로 막으므로 먼저 지운다.
        //    대화에 남은 매물 연결은 NULL 이 되고(nullOnDelete), 찜·상태 기록은 같이 지워진다(cascade).
        $posts = Post::whereIn('user_idx', User::where('status', UserStatus::Withdrawn)
            ->where('withdrawn_at', '<', $cut)->select('idx'))->get();
        $this->step('탈퇴 회원 매물', $posts->count(), function () use ($posts) {
            foreach ($posts as $post) {
                DB::transaction(function () use ($post) {
                    ContactConsent::where('post_idx', $post->idx)->delete();
                    DB::table('contact_access_logs')->where('post_idx', $post->idx)->delete();
                    $post->delete();
                });
                // 파일은 DB 가 끝난 뒤에. DB 가 되돌아갔는데 사진만 사라지면 안 된다.
                Storage::disk('local')->delete(array_column($post->photos ?? [], 'key'));
            }
        });

        // 4~9. 행만 지우는 것들
        $this->purgeRows('연락처 열람 기록', DB::table('contact_access_logs')->where('accessed_at', '<', $cut));
        $this->purgeRows('철회한 연락처 동의', ContactConsent::whereNotNull('withdrawn_at')->where('withdrawn_at', '<', $cut));
        $this->purgeRows('처리 끝난 신고', Report::whereIn('status', [ReportStatus::Resolved, ReportStatus::Dismissed])->where('updated_at', '<', $cut));
        $this->purgeRows('가입 심사 기록', ApplicationReview::where('reviewed_at', '<', $cut));
        $this->purgeRows('존속 점검 기록', ExistenceCheck::where('checked_at', '<', $cut));
        $this->purgeRows('문자 발송 기록', NotificationLog::where('sent_at', '<', $cut));

        // 10. 대화: 마지막 메시지가 1년 전인 방. 메시지는 cascade 로 같이 지워진다
        $this->purgeRows('대화방 (1년)', Inquiry::where(function ($q) use ($cut_long) {
            $q->where('last_message_at', '<', $cut_long)
                ->orWhere(fn ($q) => $q->whereNull('last_message_at')->where('created_at', '<', $cut_long));
        }));

        // 11. 운영자 조치 기록: 1년
        $this->purgeRows('운영자 조치 기록 (1년)', AuditLog::where('occurred_at', '<', $cut_long));

        $summary = collect($this->counts)->map(fn ($n, $label) => "{$label} {$n}")->implode(', ');
        $this->info(($this->dry ? '[미리보기] ' : '').$summary);

        if (! $this->dry) {
            Log::info('[purge] '.$summary);
        }

        return self::SUCCESS;
    }

    private function purgeRows(string $label, $query): void
    {
        $this->step($label, (clone $query)->count(), fn () => $query->delete());
    }

    private function step(string $label, int $count, callable $delete): void
    {
        $this->counts[$label] = $count;

        if (! $this->dry && $count > 0) {
            $delete();
        }
    }
}
