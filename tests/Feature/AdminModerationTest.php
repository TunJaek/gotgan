<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\ReportStatus;
use App\Enums\ReportTarget;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_관리자가_아니면_들어갈_수_없다(): void
    {
        $this->actingAs($this->approvedUser())->get('/admin')->assertForbidden();
        $this->actingAs($this->approvedUser('01022223333', '222-45-67891'))->get('/admin/reports')->assertForbidden();
    }

    public function test_신고는_대상별로_묶여_보인다(): void
    {
        $admin = $this->adminUser();
        $post = $this->postOf($this->approvedUser());

        // 같은 매물에 세 사람이 신고했다.
        foreach (['01011112222', '01033334444', '01055556666'] as $i => $phone) {
            $this->reportPost($post, $this->approvedUser($phone, '33'.$i.'-45-67891'));
        }

        Livewire::actingAs($admin)
            ->test('admin.reports')
            ->assertViewHas('groups', function ($groups) use ($post) {
                return $groups->count() === 1                       // 세 건이 한 줄로
                    && (int) $groups->first()->report_count === 3
                    && (int) $groups->first()->target_idx === $post->idx;
            });
    }

    public function test_글을_숨기면_선택한_신고가_함께_닫힌다(): void
    {
        $admin = $this->adminUser();
        $post = $this->postOf($this->approvedUser());
        $a = $this->reportPost($post, $this->approvedUser('01011112222', '331-45-67891'));
        $b = $this->reportPost($post, $this->approvedUser('01033334444', '332-45-67891'));

        Livewire::actingAs($admin)
            ->test('admin.report', ['type' => ReportTarget::Post->value, 'idx' => $post->idx])
            ->assertSet('selected', [(string) $a->idx, (string) $b->idx])   // 기본으로 전부 선택
            ->set('reason', '허위 매물로 확인했습니다.')
            ->call('act')
            ->assertHasNoErrors();

        $this->assertTrue($post->fresh()->is_hidden);
        $this->assertSame(ReportStatus::Resolved, $a->fresh()->status);
        $this->assertSame(ReportStatus::Resolved, $b->fresh()->status);

        // 대상 로그 1건 + 신고 로그 2건
        $this->assertSame(1, AuditLog::where('action', 'post.hidden')->count());
        $this->assertSame(2, AuditLog::where('action', 'report.resolved')->count());
    }

    public function test_고르지_않은_신고는_열려_있다(): void
    {
        $admin = $this->adminUser();
        $post = $this->postOf($this->approvedUser());
        $a = $this->reportPost($post, $this->approvedUser('01011112222', '331-45-67891'));
        $b = $this->reportPost($post, $this->approvedUser('01033334444', '332-45-67891'));

        Livewire::actingAs($admin)
            ->test('admin.report', ['type' => ReportTarget::Post->value, 'idx' => $post->idx])
            ->set('selected', [(string) $a->idx])       // 한 건만 고른다
            ->call('act');

        $this->assertSame(ReportStatus::Resolved, $a->fresh()->status);
        $this->assertSame(ReportStatus::Received, $b->fresh()->status);
    }

    public function test_기각하면_대상은_그대로다(): void
    {
        $admin = $this->adminUser();
        $post = $this->postOf($this->approvedUser());
        $report = $this->reportPost($post, $this->approvedUser('01011112222', '331-45-67891'));

        Livewire::actingAs($admin)
            ->test('admin.report', ['type' => ReportTarget::Post->value, 'idx' => $post->idx])
            ->call('dismiss')
            ->assertHasNoErrors();

        $this->assertFalse($post->fresh()->is_hidden);
        $this->assertSame(ReportStatus::Dismissed, $report->fresh()->status);
    }

    public function test_이미_숨긴_글은_신고만_닫고_로그를_또_남기지_않는다(): void
    {
        $admin = $this->adminUser();
        $post = $this->postOf($this->approvedUser());
        $post->update(['is_hidden' => true]);

        $report = $this->reportPost($post, $this->approvedUser('01011112222', '331-45-67891'));

        Livewire::actingAs($admin)
            ->test('admin.report', ['type' => ReportTarget::Post->value, 'idx' => $post->idx])
            ->call('resolveOnly')
            ->assertHasNoErrors();

        $this->assertSame(ReportStatus::Resolved, $report->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'post.hidden')->count());
    }

    public function test_관리자는_숨긴_매물을_주소로_열_수_있다(): void
    {
        // Livewire::test 로 부르면 라우트 바인딩을 안 거쳐서 404 가 가려진다. 실제 주소로 연다.
        $admin = $this->adminUser();
        $post = $this->postOf($this->approvedUser());
        $post->update(['is_hidden' => true]);

        $this->actingAs($admin)->get("/admin/posts/{$post->idx}")
            ->assertOk()
            ->assertSee('신고 확인용 글');
    }

    public function test_숨김_해제는_사유가_있어야_한다(): void
    {
        $admin = $this->adminUser();
        $post = $this->postOf($this->approvedUser());
        $post->update(['is_hidden' => true]);

        Livewire::actingAs($admin)
            ->test('admin.post', ['post' => $post->idx])
            ->call('unhide')
            ->assertHasErrors('reason');

        $this->assertTrue($post->fresh()->is_hidden);
    }

    public function test_숨김을_해제해도_판매_상태는_그대로다(): void
    {
        $admin = $this->adminUser();
        $post = $this->postOf($this->approvedUser());
        $post->update(['is_hidden' => true, 'status' => PostStatus::Stopped]);

        Livewire::actingAs($admin)
            ->test('admin.post', ['post' => $post->idx])
            ->set('reason', '확인 결과 문제가 없었습니다.')
            ->call('unhide')
            ->assertHasNoErrors();

        $fresh = $post->fresh();
        $this->assertFalse($fresh->is_hidden);
        $this->assertSame(PostStatus::Stopped, $fresh->status);   // 판매자가 정한 값은 건드리지 않는다
        $this->assertSame(1, AuditLog::where('action', 'post.unhidden')->count());
    }

    public function test_회원_정지와_복구(): void
    {
        $admin = $this->adminUser();
        $member = $this->approvedUser('01011112222', '331-45-67891');

        Livewire::actingAs($admin)
            ->test('admin.user', ['user' => $member])
            ->set('reason', '반복 신고 누적')
            ->call('suspend')
            ->assertHasNoErrors();

        $this->assertSame(UserStatus::Suspended, $member->fresh()->status);

        Livewire::actingAs($admin)
            ->test('admin.user', ['user' => $member->fresh()])
            ->set('reason', '소명을 확인했습니다.')
            ->call('reinstate')
            ->assertHasNoErrors();

        $this->assertSame(UserStatus::Active, $member->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'user.suspended')->count());
        $this->assertSame(1, AuditLog::where('action', 'user.reinstated')->count());
    }

    public function test_복구해도_신고의_조치완료는_유지된다(): void
    {
        $admin = $this->adminUser();
        $post = $this->postOf($this->approvedUser());
        $report = $this->reportPost($post, $this->approvedUser('01011112222', '331-45-67891'));

        Livewire::actingAs($admin)
            ->test('admin.report', ['type' => ReportTarget::Post->value, 'idx' => $post->idx])
            ->call('act');

        Livewire::actingAs($admin)
            ->test('admin.post', ['post' => $post->idx])
            ->set('reason', '오인 신고였습니다.')
            ->call('unhide');

        $this->assertFalse($post->fresh()->is_hidden);
        $this->assertSame(ReportStatus::Resolved, $report->fresh()->status);   // 처리 결과는 그대로
    }

    public function test_정지하면_노출중인_매물도_숨는다(): void
    {
        $admin = $this->adminUser();
        $member = $this->approvedUser('01011112222', '331-45-67891');

        $exposed = $this->postOf($member);                      // 노출 중
        $self_hidden = $this->postOf($member);                  // 판매자가 이미 내려둔 것
        $self_hidden->update(['is_hidden' => true]);

        Livewire::actingAs($admin)
            ->test('admin.user', ['user' => $member])
            ->set('reason', '반복 신고 누적')
            ->call('suspend')
            ->assertHasNoErrors();

        $this->assertTrue($exposed->fresh()->is_hidden);
        $this->assertSame(1, AuditLog::where('action', 'post.hidden.by_suspend')->count());

        // 이미 숨겨져 있던 것은 건드리지 않는다 — 로그도 남지 않는다
        $this->assertSame(0, AuditLog::where('target_type', 'post')
            ->where('target_idx', $self_hidden->idx)
            ->count());
    }

    public function test_정지를_풀면_그때_숨긴_매물만_되살아난다(): void
    {
        $admin = $this->adminUser();
        $member = $this->approvedUser('01011112222', '331-45-67891');

        $by_suspend = $this->postOf($member);                   // 정지 때문에 숨을 매물
        $by_admin = $this->postOf($member);                     // 운영자가 따로 숨긴 매물

        Livewire::actingAs($admin)
            ->test('admin.post', ['post' => $by_admin->idx])
            ->set('reason', '허위 매물')
            ->call('hide');

        Livewire::actingAs($admin)
            ->test('admin.user', ['user' => $member])
            ->set('reason', '반복 신고 누적')
            ->call('suspend');

        $this->assertTrue($by_suspend->fresh()->is_hidden);
        $this->assertTrue($by_admin->fresh()->is_hidden);

        Livewire::actingAs($admin)
            ->test('admin.user', ['user' => $member->fresh()])
            ->set('reason', '소명을 확인했습니다.')
            ->call('reinstate')
            ->assertHasNoErrors();

        $this->assertFalse($by_suspend->fresh()->is_hidden);    // 정지로 숨은 것만 복구
        $this->assertTrue($by_admin->fresh()->is_hidden);       // 따로 숨긴 것은 그대로
    }

    public function test_관리자는_정지할_수_없다(): void
    {
        $admin = $this->adminUser();
        $other = $this->adminUser('01088889999', '999-45-67891');

        Livewire::actingAs($admin)
            ->test('admin.user', ['user' => $other])
            ->set('reason', '실수로 눌렀다')
            ->call('suspend')
            ->assertHasErrors('reason');

        $this->assertSame(UserStatus::Active, $other->fresh()->status);
    }

    private function reportPost(Post $post, User $reporter): Report
    {
        return Report::create([
            'reporter_idx' => $reporter->idx,
            'target_type' => ReportTarget::Post,
            'target_idx' => $post->idx,
            'reason' => '사진과 설명이 다릅니다.',
            'status' => ReportStatus::Received,
        ]);
    }

    private function postOf(User $user): Post
    {
        return Post::create([
            'user_idx' => $user->idx,
            'post_type' => PostType::Sell,
            'category_id' => 15,
            'title' => '신고 확인용 글',
            'description' => '설명입니다.',
            'status' => PostStatus::Selling,
            'is_hidden' => false,
            'region_code' => '11590102', 'region_name' => '서울 동작구 상도동',
            'photos' => [],
        ]);
    }

    private function adminUser(string $phone = '01000001111', string $number = '111-45-67891'): User
    {
        $user = $this->approvedUser($phone, $number);
        $user->update(['role' => UserRole::Admin]);

        return $user->fresh();
    }

    private function approvedUser(string $phone = '01012345678', string $number = '123-45-67891'): User
    {
        $user = new User([
            'password' => 'Abcd123!',
            'phone' => $phone,
            'phone_verified_at' => now(),
            'name' => '홍길동',
            'company_name' => '대성포장산업',
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
