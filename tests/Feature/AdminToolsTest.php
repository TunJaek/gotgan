<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\User;
use App\Support\Moderation;
use App\Support\Pass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** 2026-09-29 점검에서 나온 것들: 정지 처리, 거래 끝난 글, 입력 길이, 관리자 분리, 운영자 도구 */
class AdminToolsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    // ── 정지 ─────────────────────────────────────────

    public function test_정지하면_로그인이_끊기고_사유가_회원에게_보이며_해제하면_사유가_지워진다(): void
    {
        $member = $this->member();
        $member->forceFill(['remember_token' => 'old-token'])->save();
        DB::table('sessions')->insert(['id' => 'member-device', 'user_id' => $member->idx, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin());
        Moderation::suspendUser($member, '거래 사기 신고 다수');

        $fresh = $member->fresh();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $member->idx)->count());
        $this->assertNotSame('old-token', $fresh->remember_token);
        $this->assertSame('거래 사기 신고 다수', $fresh->suspended_reason);
        $this->actingAs($fresh)->get(route('account.index'))->assertSee('거래 사기 신고 다수');

        Moderation::reinstateUser($fresh, '소명 확인');
        $this->assertNull($member->fresh()->suspended_reason);
    }

    // ── 거래 끝난 글 ─────────────────────────────────

    public function test_거래완료된_글에는_새_문의와_연락처_보기를_막는다(): void
    {
        $post = $this->makePost($this->member(), ['status' => PostStatus::Done, 'show_phone' => true]);
        $buyer = $this->member();

        Livewire::actingAs($buyer)->test('posts.detail', ['post' => $post])
            ->assertSee('거래가 끝난 글이라 새 문의와 연락처 보기는 할 수 없습니다')
            ->assertDontSee('문의하기')
            ->call('revealContact')
            ->assertSet('contact_visible', false)
            ->call('openInquiry')
            ->assertSet('inquiry_open', false)
            ->set('inquiry_message', '아직 살 수 있나요?')
            ->call('sendInquiry')
            ->assertForbidden();

        $this->assertSame(0, DB::table('contact_access_logs')->count());
    }

    // ── 입력 길이 ────────────────────────────────────

    public function test_너무_긴_설명과_금액은_자동저장하지_않고_등록도_막는다(): void
    {
        $c = Livewire::actingAs($this->member())->test('posts.create')
            ->set('ignore_draft', true)
            ->set('title', '길이 점검용 제목');                      // 여기서 임시저장 글이 생긴다

        $c->set('description', str_repeat('가', 5001))
            ->assertHasErrors('description');                     // 500 대신 저장을 건너뛰고 알린다
        $this->assertSame('', (string) Post::first()->description);

        $c->set('description', '정상 설명')
            ->set('price_amount', 1_000_000_000_000)                  // 13자리
            ->assertHasErrors('price_amount');
        $this->assertSame('정상 설명', Post::first()->description);
        $this->assertNull(Post::first()->price_amount);            // 큰 금액은 저장되지 않았다

        $c->call('save')->assertHasErrors(['price_amount', 'photos']);
    }

    // ── 관리자 ───────────────────────────────────────

    public function test_관리자도_매물_등록과_거래_기능을_쓸_수_있다(): void
    {
        $admin = $this->admin();
        $post = $this->makePost($this->member(), ['show_phone' => true]);

        $this->actingAs($admin)->get(route('posts.create'))->assertOk();
        Livewire::actingAs($admin)->test('posts.detail', ['post' => $post->fresh()])
            ->call('toggleFavorite')->assertHasNoErrors()
            ->call('revealContact')->assertSet('contact_visible', true)
            ->call('openInquiry')->assertSet('inquiry_open', true)
            ->call('openReport')->assertSet('report_open', true);
        $this->assertSame(1, DB::table('contact_access_logs')->count());   // 관리자 열람도 기록은 남는다
    }

    public function test_승인_전_회원은_상세_버튼을_눌러도_다른_화면으로_가지_않고_이유가_보인다(): void
    {
        $pending = $this->member();
        $pending->forceFill(['review_status' => \App\Enums\ApplicationStatus::Reviewing])->save();
        $post = $this->makePost($this->member(), ['show_phone' => true]);

        Livewire::actingAs($pending)->test('posts.detail', ['post' => $post->fresh()])
            ->call('toggleFavorite')->assertNoRedirect()->assertHasErrors('action')
            ->call('revealContact')->assertNoRedirect()->assertSee('사업자 확인을 마친 회원만 이용할 수 있습니다')
            ->call('openInquiry')->assertSet('inquiry_open', false)
            ->call('openReport')->assertSet('report_open', false)->assertHasErrors('report');
    }

    public function test_관리자_계정은_관리자_전용_번호로만_만든다(): void
    {
        $this->artisan('gotgan:admin', ['number' => '220-81-62517', 'phone' => '01011112222', '--password' => 'Admin123!'])
            ->expectsOutputToContain('실제 사업자번호 형식입니다')->assertFailed();
        $this->artisan('gotgan:admin', ['number' => '000-00-00001', 'phone' => '01011112222', '--password' => 'weak'])
            ->assertFailed();
        $this->artisan('gotgan:admin', ['number' => '000-00-00001', 'phone' => '01011112222', '--password' => 'Admin123!'])
            ->assertSuccessful();

        $admin = User::where('biz_no', '0000000001')->first();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->isApproved());                   // 회원 기능도 쓸 수 있다(2026-09-30)
    }

    // ── 운영자 도구 ──────────────────────────────────

    public function test_운영자가_상호와_대표자명을_바꾸면_사유와_이전_값이_남는다(): void
    {
        $member = $this->member();

        Livewire::actingAs($this->admin())->test('admin.user', ['user' => $member])
            ->set('company_name', '새상호')
            ->set('owner_name', '김대표')
            ->call('saveProfile')
            ->assertHasErrors('profile_reason')
            ->set('profile_reason', '사업자등록증 변경 확인')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $this->assertSame('새상호', $member->fresh()->company_name);
        $log = AuditLog::where('action', 'user.profile.changed')->first();
        $this->assertStringContainsString('변경 전: 회원', $log->reason);
    }

    public function test_휴대폰_초기화_뒤_새_번호로_본인인증하면_정보_수정에_들어갈_수_있다(): void
    {
        $member = $this->member();   // CI-1 로 묶여 있다

        Livewire::actingAs($this->admin())->test('admin.user', ['user' => $member])
            ->set('new_phone', '010-9999-0000')
            ->set('identity_reason', '번호 변경, 대표자 통화 확인')
            ->call('resetIdentity')
            ->assertHasNoErrors();

        $fresh = $member->fresh();
        $this->assertSame('01099990000', $fresh->phone);
        $this->assertNull($fresh->ci_hash);

        // 새 번호로 본인인증하면 새 CI 가 묶이고 수정 화면이 열린다
        Pass::remember('edit', '010-9999-0000', '홍길동', 'CI-NEW');
        Livewire::actingAs($fresh)->test('account.edit')->assertNoRedirect()->assertSee('비밀번호 변경');
        $this->assertSame(Pass::ciHash('CI-NEW'), $member->fresh()->ci_hash);
    }

    public function test_운영자_탈퇴_처리는_사유가_남고_관리자는_대상이_아니다(): void
    {
        $member = $this->member(['status' => UserStatus::Suspended]);
        $admin = $this->admin();

        Livewire::actingAs($admin)->test('admin.user', ['user' => $member])
            ->set('withdraw_reason', '정지 회원 탈퇴 요청')
            ->call('withdrawMember')
            ->assertHasNoErrors();

        $this->assertSame(UserStatus::Withdrawn, $member->fresh()->status);
        $this->assertSame('정지 회원 탈퇴 요청', AuditLog::where('action', 'user.withdrawn.by_admin')->value('reason'));

        Livewire::actingAs($admin)->test('admin.user', ['user' => $this->admin()])
            ->set('withdraw_reason', '시험')
            ->call('withdrawMember')
            ->assertForbidden();
    }

    // ── 도우미 ───────────────────────────────────────

    private function makePost(User $user, array $attrs = []): Post
    {
        return Post::create($attrs + [
            'user_idx' => $user->idx, 'post_type' => PostType::Sell, 'category_id' => 15, 'title' => '점검용 매물',
            'description' => '설명', 'condition' => 1, 'status' => PostStatus::Selling, 'is_hidden' => false,
            'price_amount' => 10000, 'region_code' => '11590102', 'region_name' => '서울 동작구 상도동', 'photos' => [],
        ]);
    }

    private function member(array $attrs = []): User
    {
        $this->n++;
        $user = new User($attrs + [
            'password' => 'Abcd123!', 'phone' => sprintf('010%08d', $this->n), 'phone_verified_at' => now(),
            'ci_hash' => Pass::ciHash('CI-'.$this->n),
            'name' => '홍길동', 'company_name' => '회원'.$this->n, 'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => UserRole::Member, 'status' => UserStatus::Active, 'review_status' => ApplicationStatus::Approved,
            'submitted_at' => now(), 'approved_at' => now(),
            'existence_status' => ExistenceStatus::Active, 'existence_checked_at' => now(),
        ]);
        $user->biz_no = sprintf('%010d', 1000000000 + $this->n);
        $user->save();

        return $user->fresh();
    }

    private function admin(): User
    {
        return $this->member(['role' => UserRole::Admin]);
    }
}
