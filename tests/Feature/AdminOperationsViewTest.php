<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceCheckResult;
use App\Enums\ExistenceStatus;
use App\Enums\ReviewDecision;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ExistenceCheck;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminOperationsViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_관리자가_아니면_운영_이력을_볼_수_없다(): void
    {
        $member = $this->approvedUser();

        $this->actingAs($member)->get('/admin/existence-checks')->assertForbidden();
        $this->actingAs($member)->get('/admin/notification-logs')->assertForbidden();
    }

    public function test_관리자가_존속_점검과_문자_이력_화면을_열_수_있다(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->get('/admin/existence-checks')->assertOk()->assertSee('사업자 존속 점검');
        $this->actingAs($admin)->get('/admin/notification-logs')->assertOk()->assertSee('문자 발송 이력');
    }

    public function test_존속_점검은_상호로_검색하고_이상_결과만_모아본다(): void
    {
        $admin = $this->adminUser();
        $active = $this->approvedUser('01022223333', '222-45-67891', '한결포장');
        $closed = $this->approvedUser('01033334444', '333-45-67891', '푸른재고');

        ExistenceCheck::create([
            'user_idx' => $active->idx,
            'checked_at' => now()->subMinute(),
            'result' => ExistenceCheckResult::Active,
            'raw_status_code' => '01',
        ]);
        ExistenceCheck::create([
            'user_idx' => $closed->idx,
            'checked_at' => now(),
            'result' => ExistenceCheckResult::Closed,
            'raw_status_code' => '03',
            'action_taken' => '매물 자동 숨김',
        ]);

        Livewire::actingAs($admin)
            ->test('admin.existence-checks')
            ->set('tab', 'attention')
            ->assertSee('푸른재고')
            ->assertDontSee('한결포장')
            ->set('tab', 'all')
            ->set('keyword', '한결')
            ->assertSee('한결포장')
            ->assertDontSee('푸른재고');
    }

    public function test_문자_이력은_실패_사유와_발송_결과를_구분한다(): void
    {
        $admin = $this->adminUser();
        $success = $this->approvedUser('01022223333', '222-45-67891', '성공상사');
        $failed = $this->approvedUser('01033334444', '333-45-67891', '실패상사');

        NotificationLog::create([
            'type' => ReviewDecision::Approved,
            'recipient_user_idx' => $success->idx,
            'success' => true,
            'provider_message_id' => 'msg-success',
            'sent_at' => now()->subMinute(),
        ]);
        NotificationLog::create([
            'type' => ReviewDecision::Rejected,
            'recipient_user_idx' => $failed->idx,
            'success' => false,
            'error_message' => '발송사 응답 시간 초과',
            'sent_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('admin.notification-logs')
            ->set('tab', 'failed')
            ->assertSee('실패상사')
            ->assertSee('발송사 응답 시간 초과')
            ->assertDontSee('성공상사');
    }

    private function adminUser(): User
    {
        $user = $this->approvedUser('01000001111', '111-45-67891', '관리상사');
        $user->update(['role' => UserRole::Admin]);

        return $user->fresh();
    }

    private function approvedUser(
        string $phone = '01012345678',
        string $number = '123-45-67891',
        string $company = '대성포장산업',
    ): User {
        $user = new User([
            'password' => 'Abcd123!',
            'phone' => $phone,
            'phone_verified_at' => now(),
            'name' => '홍길동',
            'company_name' => $company,
            'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
            'review_status' => ApplicationStatus::Approved,
            'submitted_at' => now(),
            'approved_at' => now(),
            'existence_status' => ExistenceStatus::Active,
            'existence_checked_at' => now(),
        ]);
        $user->biz_no = $number;
        $user->save();

        return $user->fresh();
    }
}
