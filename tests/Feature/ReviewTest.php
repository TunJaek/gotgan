<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ReviewDecision;
use App\Enums\ExistenceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApplicationReview;
use App\Models\AuditLog;
use App\Models\NotificationLog;
use App\Models\User;
use App\Support\BizNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $applicant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('211-88-12346', '01011112222', UserRole::Admin);
        $this->applicant = $this->makeUser('123-45-67891', '01012345678');
    }

    public function test_관리자가_아니면_심사화면에_못_들어간다(): void
    {
        $this->actingAs(User::whereRole('member')->first());
        $this->get(route('admin.applications'))->assertForbidden();

        $this->actingAs($this->admin);
        $this->get(route('admin.applications'))->assertOk();
    }

    public function test_승인하면_사업체가_승인되고_문자가_기록된다(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('admin.review', ['user' => $this->applicant])
            ->set('decision', ReviewDecision::Approved->value)
            ->call('save')
            ->assertHasNoErrors();

        $applicant = $this->applicant->fresh();
        $this->assertSame(ApplicationStatus::Approved, $applicant->review_status);
        $this->assertSame(1, $applicant->lock_version);
        $this->assertNotNull($applicant->approved_at);

        $log = NotificationLog::first();
        $this->assertSame(ReviewDecision::Approved, $log->type);
        $this->assertTrue($log->success);
        $this->assertSame(1, ApplicationReview::count());
    }

    public function test_보완과_반려는_사유가_있어야_한다(): void
    {
        $this->actingAs($this->admin);

        foreach ([ReviewDecision::Supplement, ReviewDecision::Rejected] as $decision) {
            Livewire::test('admin.review', ['user' => $this->applicant])
                ->set('decision', $decision->value)
                ->set('reason', '   ')
                ->call('save')
                ->assertHasErrors('reason');
        }

        $this->assertSame(ApplicationStatus::Reviewing, $this->applicant->fresh()->review_status);
    }

    public function test_보완요청하면_재제출_회차가_올라간다(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('admin.review', ['user' => $this->applicant])
            ->set('decision', ReviewDecision::Supplement->value)
            ->set('reason', '등록증 사진이 흐려 상호를 확인할 수 없습니다.')
            ->call('save')
            ->assertHasNoErrors();

        $applicant = $this->applicant->fresh();
        $this->assertSame(ApplicationStatus::Supplement, $applicant->review_status);
        $this->assertSame(2, $applicant->submission_revision);
        $this->assertSame(ReviewDecision::Supplement, NotificationLog::first()->type);
    }

    public function test_다른_관리자가_먼저_처리했으면_막힌다(): void
    {
        $this->actingAs($this->admin);

        $component = Livewire::test('admin.review', ['user' => $this->applicant])
            ->set('decision', ReviewDecision::Rejected->value)
            ->set('reason', '폐업 사업자로 확인됩니다.');

        // 그 사이 다른 관리자가 처리해 lock_version 이 올라간 상황
        User::where('idx', $this->applicant->idx)->update(['lock_version' => 5]);

        $component->call('save')->assertHasErrors('decision');
        $this->assertSame(0, ApplicationReview::count());
    }

    public function test_이미_처리된_신청은_다시_처리되지_않는다(): void
    {
        $this->applicant->update(['review_status' => ApplicationStatus::Approved]);
        $this->actingAs($this->admin);

        Livewire::test('admin.review', ['user' => $this->applicant])
            ->set('decision', ReviewDecision::Rejected->value)
            ->set('reason', '되돌리기 시도')
            ->call('save')
            ->assertHasErrors('decision');
    }

    public function test_심사_기록은_이름으로_남고_관리자_화면에는_한국어로_보인다(): void
    {
        $this->actingAs($this->admin);

        Livewire::test('admin.review', ['user' => $this->applicant])
            ->set('decision', ReviewDecision::Approved->value)
            ->call('save')
            ->assertHasNoErrors();

        // 숫자(application.3)가 아니라 이름으로 남는다
        $this->assertSame(1, AuditLog::where('action', 'application.approved')->count());

        Livewire::test('admin.user', ['user' => $this->applicant->fresh()])
            ->assertSee('가입 승인')
            ->assertDontSee('application.approved');
    }

    public function test_비밀번호_재설정_기록의_처리자는_본인으로_보인다(): void
    {
        $log = AuditLog::create([
            'actor_idx' => null, 'action' => 'user.password.reset',
            'target_type' => 'user', 'target_idx' => $this->applicant->idx, 'occurred_at' => now(),
        ]);

        $this->assertSame('비밀번호 재설정', $log->label());
        $this->assertSame('본인 (문자 인증)', $log->actorLabel());

        // 표에 없는 값은 저장값 그대로, 처리자가 없으면 시스템
        $other = new AuditLog(['action' => 'something.new']);
        $this->assertSame('something.new', $other->label());
        $this->assertSame('시스템', $other->actorLabel());
    }

    private function makeUser(string $bizNumber, string $phone, UserRole $role = UserRole::Member): User
    {
        $user = new User([
            'password' => 'Abcd123!',
            'phone' => $phone,
            'phone_verified_at' => now(),
            'name' => $role === UserRole::Admin ? '운영자' : '홍길동',
            'company_name' => $role === UserRole::Admin ? '곳간 운영' : '대성포장산업',
            'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => $role,
            'status' => UserStatus::Active,
            'review_status' => $role === UserRole::Admin ? ApplicationStatus::Approved : ApplicationStatus::Reviewing,
            'submitted_at' => now(),
            'existence_status' => ExistenceStatus::Active,
            'existence_checked_at' => now(),
        ]);
        $user->biz_no = $bizNumber;
        $user->save();

        return $user->fresh();
    }
}
