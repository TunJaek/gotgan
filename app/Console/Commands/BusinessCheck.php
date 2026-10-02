<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceCheckResult;
use App\Models\ExistenceCheck;
use App\Models\User;
use App\Support\BiznoStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * 승인된 회원의 사업자 상태를 국세청에 물어보고 existence_checks 에 남긴다.
 * 결과는 관리자 → 존속 점검 화면에서 본다.
 *
 * 지금은 사람이 직접 돌린다. 스케줄 등록, users.existence_status 갱신,
 * 폐업 회원 매물 숨김 같은 자동 조치는 출시 후에 붙인다(2026-09-28 결정).
 */
#[Signature('app:business-check {--limit=0 : 앞에서부터 몇 명만 조회. 0이면 전체}')]
#[Description('승인된 회원의 사업자 상태를 국세청에 조회해 기록합니다.')]
class BusinessCheck extends Command
{
    public function handle(): int
    {
        $users = User::where('review_status', ApplicationStatus::Approved)
            ->whereNotNull('biz_no')
            ->orderBy('idx')
            ->when((int) $this->option('limit'), fn ($q, $limit) => $q->limit($limit))
            ->get();

        $counts = [];

        // ponytail: 한 명씩 부른다. 국세청은 한 번에 100개까지 받으니 회원이 수천 명이 되면 묶어서 부른다.
        foreach ($users as $user) {
            $status = BiznoStatus::check($user->biz_no);

            $result = match ($status['result']) {
                'active' => ExistenceCheckResult::Active,
                'closed' => ExistenceCheckResult::Closed,
                'suspended' => ExistenceCheckResult::Suspended,
                'not_found' => ExistenceCheckResult::Unregistered,
                // 통신 오류·키 누락은 '조회 실패'다. 폐업으로 보지 않는다.
                default => ExistenceCheckResult::Failed,
            };

            ExistenceCheck::create([
                'user_idx' => $user->idx,
                'checked_at' => now(),
                'result' => $result,
                // 성공하면 국세청 상태코드(01·02·03), 실패하면 오류 문구 앞부분을 남긴다.
                'raw_status_code' => $status['code'] ?? mb_substr((string) $status['error'], 0, 50),
            ]);

            $counts[$result->label()] = ($counts[$result->label()] ?? 0) + 1;
        }

        $this->info("조회 {$users->count()}명");
        foreach ($counts as $label => $count) {
            $this->line("  {$label} {$count}명");
        }

        return self::SUCCESS;
    }
}
