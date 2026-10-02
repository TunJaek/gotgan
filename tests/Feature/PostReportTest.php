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
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PostReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_신고하면_접수된다(): void
    {
        $post = $this->postOf($this->approvedUser());
        $reporter = $this->approvedUser('01099998888', '789-45-67891');

        Livewire::actingAs($reporter)
            ->test('posts.detail', ['post' => $post])
            ->call('openReport')
            ->assertSet('report_open', true)
            ->set('report_reason', '사진과 설명이 다릅니다.')
            ->call('report')
            ->assertHasNoErrors()
            ->assertSet('report_open', false)
            ->assertSet('reported', true);

        $report = DB::table('reports')->first();
        $this->assertSame($reporter->idx, $report->reporter_idx);
        $this->assertSame(1, $report->target_type);
        $this->assertSame($post->idx, $report->target_idx);
        $this->assertSame(0, $report->status);
    }

    public function test_사유가_짧으면_접수되지_않는다(): void
    {
        $post = $this->postOf($this->approvedUser());
        $reporter = $this->approvedUser('01099998888', '789-45-67891');

        Livewire::actingAs($reporter)
            ->test('posts.detail', ['post' => $post])
            ->set('report_reason', '어')
            ->call('report')
            ->assertHasErrors('report_reason');

        $this->assertSame(0, DB::table('reports')->count());
    }

    public function test_검토_중인_신고가_있으면_다시_접수하지_않는다(): void
    {
        $post = $this->postOf($this->approvedUser());
        $reporter = $this->approvedUser('01099998888', '789-45-67891');

        Livewire::actingAs($reporter)
            ->test('posts.detail', ['post' => $post])
            ->set('report_reason', '처음 신고합니다.')
            ->call('report')
            ->assertHasNoErrors();

        // 두 번째는 조용히 버리지 않고 왜 안 되는지 알려준다.
        Livewire::actingAs($reporter)
            ->test('posts.detail', ['post' => $post])
            ->set('report_reason', '다시 신고합니다.')
            ->call('report')
            ->assertHasErrors('report_reason');

        $this->assertSame(1, DB::table('reports')->count());
    }

    public function test_기각된_뒤_3일_안에는_다시_신고할_수_없다(): void
    {
        $post = $this->postOf($this->approvedUser());
        $reporter = $this->approvedUser('01099998888', '789-45-67891');

        Report::create([
            'reporter_idx' => $reporter->idx,
            'target_type' => ReportTarget::Post,
            'target_idx' => $post->idx,
            'reason' => '예전에 신고했던 건입니다.',
            'status' => ReportStatus::Dismissed,
        ]);

        Livewire::actingAs($reporter)
            ->test('posts.detail', ['post' => $post])
            ->set('report_reason', '또 신고합니다.')
            ->call('report')
            ->assertHasErrors('report_reason');

        $this->assertSame(1, DB::table('reports')->count());
    }

    public function test_기각된_지_3일이_지나면_다시_신고할_수_있다(): void
    {
        $post = $this->postOf($this->approvedUser());
        $reporter = $this->approvedUser('01099998888', '789-45-67891');

        $old = Report::create([
            'reporter_idx' => $reporter->idx,
            'target_type' => ReportTarget::Post,
            'target_idx' => $post->idx,
            'reason' => '예전에 신고했던 건입니다.',
            'status' => ReportStatus::Dismissed,
        ]);
        $old->forceFill(['updated_at' => now()->subDays(4)])->saveQuietly();

        Livewire::actingAs($reporter)
            ->test('posts.detail', ['post' => $post])
            ->set('report_reason', '그때와 다른 문제가 생겼습니다.')
            ->call('report')
            ->assertHasNoErrors();

        $this->assertSame(2, DB::table('reports')->count());
    }

    public function test_다른_사람은_같은_글을_신고할_수_있다(): void
    {
        $post = $this->postOf($this->approvedUser());
        $a = $this->approvedUser('01099998888', '789-45-67891');
        $b = $this->approvedUser('01077776666', '456-45-67891');

        foreach ([$a, $b] as $reporter) {
            Livewire::actingAs($reporter)
                ->test('posts.detail', ['post' => $post])
                ->set('report_reason', '사진과 설명이 다릅니다.')
                ->call('report')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, DB::table('reports')->count());
    }

    public function test_자기_글은_신고할_수_없다(): void
    {
        $owner = $this->approvedUser();
        $post = $this->postOf($owner);

        Livewire::actingAs($owner)
            ->test('posts.detail', ['post' => $post])
            ->set('report_reason', '자기 글을 신고해 봅니다.')
            ->call('report')
            ->assertForbidden();

        $this->assertSame(0, DB::table('reports')->count());
    }

    public function test_비회원은_로그인으로_보낸다(): void
    {
        $post = $this->postOf($this->approvedUser());

        Livewire::test('posts.detail', ['post' => $post])
            ->call('openReport')
            ->assertRedirect(route('login'))
            ->assertSet('report_open', false);
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
