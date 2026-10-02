<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\PostStatus;
use App\Enums\ReportTarget;
use App\Enums\ReviewDecision;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\ContactConsent;
use App\Models\Inquiry;
use App\Models\NotificationLog;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\Pass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 출시 전 최종 검증. 기능 하나씩이 아니라 기능과 기능 사이가 이어지는지를 본다.
 * 판매자 A, 구매자 B, 관리자가 가입부터 탈퇴까지 한 번에 따라간다.
 */
class LaunchFlowTest extends TestCase
{
    use RefreshDatabase;

    private const A_BIZ = '345-67-89013';
    private const A_PHONE = '010-3333-4444';
    private const B_BIZ = '456-78-90121';
    private const B_PHONE = '010-5555-6666';

    public function test_가입부터_탈퇴까지_한_번에_따라간다(): void
    {
        Storage::fake('local');
        $admin = $this->makeAdmin();

        // ── 1. A 가입 ─────────────────────────────────
        $this->register(self::A_BIZ, self::A_PHONE, '한결포장')->assertRedirect(route('register.complete'));
        $a = User::where('biz_no', '3456789013')->firstOrFail();
        $this->assertSame(ApplicationStatus::Reviewing, $a->review_status);
        $this->assertSame(2, Agreement::where('user_idx', $a->idx)->count());
        $this->assertTrue(Storage::disk('local')->exists($a->licenseKey()));

        // ── 2. 보완 요청 → 보완 재제출 ─────────────────
        $this->review($admin, $a, ReviewDecision::Supplement, '상호가 등록증과 다릅니다.');
        $this->assertSame(ApplicationStatus::Supplement, $a->fresh()->review_status);

        Livewire::actingAs($a->fresh())->test('account.supplement')
            ->assertSee('상호가 등록증과 다릅니다.')
            ->set('company_name', '한결포장(주)')
            ->call('submit')
            ->assertRedirect(route('account.index'));
        $a = $a->fresh();
        $this->assertSame(ApplicationStatus::Reviewing, $a->review_status);
        $this->assertSame(2, $a->submission_revision);

        // ── 3. 승인 ───────────────────────────────────
        $this->review($admin, $a, ReviewDecision::Approved);
        $a = $a->fresh();
        $this->assertTrue($a->isApproved());
        $this->assertSame(2, NotificationLog::where('recipient_user_idx', $a->idx)->count());   // 보완 + 승인 문자

        // ── 4. A 매물 등록 (연락처 공개 + 동의) ─────────
        Livewire::actingAs($a)->test('posts.create')
            ->set('photos', [UploadedFile::fake()->image('box.jpg')])
            ->set('title', '골판지 상자 3호 500장')
            ->set('description', '창고 정리 물량입니다.')
            ->set('condition', 1)
            ->set('region_code', '11590102')->set('region_name', '서울 동작구 상도동')
            ->set('price_amount', 50000)
            ->set('show_phone', '1')
            ->set('phone_consent', true)
            ->call('save')
            ->assertHasNoErrors();
        $post = Post::where('user_idx', $a->idx)->where('status', PostStatus::Selling)->firstOrFail();
        $this->assertTrue($post->show_phone);
        $this->assertSame(1, ContactConsent::whereNull('withdrawn_at')->count());

        Auth::logout();
        Livewire::test('posts.index')->assertSee('골판지 상자 3호 500장');   // 비로그인도 목록에서 본다

        // ── 5. B 가입·승인 → 연락처 보기 ────────────────
        $this->register(self::B_BIZ, self::B_PHONE, '대성상사');
        $b = User::where('biz_no', '4567890121')->firstOrFail();
        $this->review($admin, $b, ReviewDecision::Approved);
        $b = $b->fresh();

        Livewire::actingAs($b)->test('posts.detail', ['post' => $post])
            ->assertDontSee('010-3333-4444')
            ->call('revealContact')
            ->assertSee('010-3333-4444');
        $this->assertSame(1, DB::table('contact_access_logs')->where('viewer_user_idx', $b->idx)->count());

        // ── 6. B 문의 → A 답장 ─────────────────────────
        Livewire::actingAs($b)->test('posts.detail', ['post' => $post])
            ->call('openInquiry')
            ->set('inquiry_message', '500장 전부 가능한가요?')
            ->call('sendInquiry')
            ->assertHasNoErrors();
        $room = Inquiry::findBetween($a->idx, $b->idx);
        $this->assertNotNull($room);
        $this->assertSame(1, $a->fresh()->unreadInquiryCount());

        Livewire::actingAs($a->fresh())->test('inquiries.chat', ['room' => $room])
            ->assertSee('500장 전부 가능한가요?')
            ->set('draft', '네, 전부 가능합니다.')
            ->call('send')
            ->assertHasNoErrors();
        $this->assertSame(0, $a->fresh()->unreadInquiryCount());   // 대화를 열면 읽음

        // ── 7. B 신고 (매물 + 회원), 반복 신고 차단 ─────
        Livewire::actingAs($b)->test('posts.detail', ['post' => $post])
            ->call('openReport')
            ->set('report_reason', '허위 매물로 보입니다.')
            ->call('report')
            ->assertHasNoErrors();

        Livewire::actingAs($b)->test('inquiries.chat', ['room' => $room])
            ->call('openReport')
            ->set('report_reason', '거래와 무관한 연락을 합니다.')
            ->call('report')
            ->assertHasNoErrors();

        Livewire::actingAs($b)->test('inquiries.chat', ['room' => $room])
            ->call('openReport')
            ->assertHasErrors('report');   // 검토 중인 신고가 있다
        $this->assertSame(2, Report::count());

        // ── 8. 관리자: 회원 신고 확인 → 이용정지 ─────────
        Livewire::actingAs($admin)
            ->test('admin.report', ['type' => ReportTarget::User->value, 'idx' => $a->idx])
            ->assertSee('거래와 무관한 연락을 합니다.')
            ->assertSee('네, 전부 가능합니다.')          // 신고자와의 대화
            ->set('reason', '반복 연락 신고 확인')
            ->call('act')
            ->assertHasNoErrors();
        $this->assertSame(UserStatus::Suspended, $a->fresh()->status);
        $this->assertTrue($post->fresh()->is_hidden);
        $this->assertSame(1, AuditLog::where('action', 'report.conversation.view')->count());

        Auth::logout();
        Livewire::test('posts.index')->assertDontSee('골판지 상자 3호 500장');
        $this->loginAttempt(self::A_BIZ, 'Abcd123!')->assertHasErrors('bizNumber');   // 정지 회원은 로그인 불가
        Auth::logout();

        // ── 9. 정지 해제 → 정지로 숨긴 매물 복구 ─────────
        Livewire::actingAs($admin)->test('admin.user', ['user' => $a->fresh()])
            ->set('reason', '소명 확인, 오해였음')
            ->call('reinstate');
        $this->assertSame(UserStatus::Active, $a->fresh()->status);
        $this->assertFalse($post->fresh()->is_hidden);

        // ── 10. A 비밀번호 찾기 ────────────────────────
        DB::table('sessions')->insert(['id' => 'a-phone', 'user_id' => $a->idx, 'payload' => '', 'last_activity' => time()]);
        Auth::logout();
        Pass::remember('reset', self::A_PHONE, '홍길동', 'CI-'.self::A_BIZ);   // 가입 때와 같은 사람
        Livewire::test('auth.reset')
            ->set('biz_number', self::A_BIZ)
            ->set('password', 'NewPass9!')->set('password_confirmation', 'NewPass9!')
            ->call('resetPassword')
            ->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $a->idx)->count());

        Auth::logout();
        $this->loginAttempt(self::A_BIZ, 'NewPass9!')->assertHasNoErrors();
        $this->assertSame($a->idx, auth()->id());

        // ── 11. A 탈퇴 ─────────────────────────────────
        Livewire::actingAs($a->fresh())->test('account.withdraw')
            ->set('password', 'NewPass9!')
            ->set('confirmed', true)
            ->call('withdraw')
            ->assertRedirect(route('login'));
        $gone = $a->fresh();
        $this->assertSame(UserStatus::Withdrawn, $gone->status);
        $this->assertTrue($post->fresh()->is_hidden);
        $this->assertFalse($post->fresh()->show_phone);
        $this->assertFalse(Storage::disk('local')->exists($gone->licenseKey()));

        // 실제 화면은 요청마다 방을 새로 읽는다. 테스트도 앞 단계에서 쓰던 객체 대신 새로 읽는다.
        Livewire::actingAs($b)->test('inquiries.chat', ['room' => $room->fresh()])
            ->assertSee('탈퇴한 회원')
            ->assertSee('네, 전부 가능합니다.')          // 대화는 B 에게 남는다
            ->assertDontSee('이 회원 신고')
            ->set('draft', '아직 계신가요?')
            ->call('send')
            ->assertHasErrors('draft');

        // ── 12. 같은 사업자번호로 재가입 ────────────────
        Auth::logout();
        $this->register(self::A_BIZ, '010-7777-8888', '한결포장 재가입')->assertRedirect(route('register.complete'));
        $again = User::where('biz_no', '3456789013')->firstOrFail();
        $this->assertNotSame($a->idx, $again->idx);
    }

    /**
     * 주요 주소를 다섯 가지 사용자로 요청해 들어가져야 할 곳과 막혀야 할 곳을 확인한다.
     * 200=열림 / 302=다른 곳으로 보냄 / 403=거부
     */
    public function test_주소별_접근_권한표(): void
    {
        $users = [
            'guest' => null,
            'reviewing' => $this->member('1234567891', '01010000001', ApplicationStatus::Reviewing),
            'approved' => $this->member('2345678904', '01010000002'),
            'suspended' => $this->member('4567890121', '01010000003', status: UserStatus::Suspended),
            'admin' => $this->makeAdmin(),
        ];
        $post = Post::create([
            'user_idx' => $users['approved']->idx, 'post_type' => 1, 'category_id' => 15,
            'title' => '권한 확인용 매물', 'description' => '설명', 'condition' => 1,
            'status' => PostStatus::Selling, 'is_hidden' => false, 'region_code' => '11590102', 'region_name' => '서울 동작구 상도동', 'photos' => [],
        ]);

        //                                    guest  reviewing approved suspended admin
        $table = [
            '/'                           => [200, 200, 200, 200, 200],
            "/posts/{$post->idx}"         => [200, 200, 200, 200, 200],
            '/terms'                      => [200, 200, 200, 200, 200],
            '/privacy'                    => [200, 200, 200, 200, 200],
            '/login'                      => [200, 302, 302, 302, 302],
            '/register'                   => [200, 302, 302, 302, 302],
            '/password/find'              => [200, 302, 302, 302, 302],
            // 관리자도 등록·문의를 쓸 수 있다(2026-09-30)
            '/posts/create'               => [302, 302, 200, 302, 200],
            '/inquiries'                  => [302, 302, 200, 302, 200],
            '/account'                    => [302, 200, 200, 200, 200],
            '/account/supplement'         => [302, 302, 302, 302, 302],
            '/account/withdraw'           => [302, 200, 200, 200, 403],
            '/account/edit'               => [302, 200, 200, 200, 200],
            '/account/posts'              => [302, 200, 200, 200, 200],
            '/admin'                      => [302, 403, 403, 403, 200],
            '/admin/applications'         => [302, 403, 403, 403, 200],
            '/admin/reports'              => [302, 403, 403, 403, 200],
            '/admin/posts'                => [302, 403, 403, 403, 200],
            '/admin/users'                => [302, 403, 403, 403, 200],
            '/admin/existence-checks'     => [302, 403, 403, 403, 200],
            '/admin/notification-logs'    => [302, 403, 403, 403, 200],
        ];

        $wrong = [];
        foreach ($table as $path => $expected) {
            foreach (array_keys($users) as $i => $who) {
                Auth::logout();
                $users[$who] ? $this->actingAs($users[$who]) : null;
                $got = $this->get($path)->baseResponse->getStatusCode();
                if ($got !== $expected[$i]) {
                    $wrong[] = "{$path} [{$who}] 기대 {$expected[$i]} / 실제 {$got}";
                }
            }
        }

        $this->assertSame([], $wrong, "권한이 표와 다른 곳:\n".implode("\n", $wrong));
    }

    // ── 도우미 ───────────────────────────────────────────

    private function register(string $biz, string $phone, string $company)
    {
        Auth::logout();
        Pass::remember('register', $phone, '홍길동', 'CI-'.$biz);   // 다날 인증을 마치고 돌아온 상태

        return Livewire::test('auth.register')
            ->set('bizNumber', $biz)
            ->set('password', 'Abcd123!')
            ->set('passwordConfirmation', 'Abcd123!')
            ->set('companyName', $company)
            ->set('ownerName', '홍길동')
            ->set('region_code', '11590102')->set('region_name', '서울 동작구 상도동')
            ->set('license', UploadedFile::fake()->image('license.jpg'))
            ->set('agreeTerms', true)
            ->set('agreePrivacy', true)
            ->call('submit');
    }

    private function review(User $admin, User $applicant, ReviewDecision $decision, string $reason = ''): void
    {
        Livewire::actingAs($admin)->test('admin.review', ['user' => $applicant->fresh()])
            ->set('decision', $decision->value)
            ->set('reason', $reason)
            ->call('save')
            ->assertHasNoErrors();
    }

    private function loginAttempt(string $biz, string $password)
    {
        return Livewire::test('auth.login')->set('bizNumber', $biz)->set('password', $password)->call('submit');
    }

    private function member(string $biz, string $phone, ApplicationStatus $review = ApplicationStatus::Approved, UserStatus $status = UserStatus::Active): User
    {
        $user = new User([
            'password' => 'Abcd123!', 'phone' => $phone, 'phone_verified_at' => now(),
            'name' => '홍길동', 'company_name' => '회원'.$phone, 'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => UserRole::Member, 'status' => $status, 'review_status' => $review,
            'submitted_at' => now(), 'approved_at' => $review === ApplicationStatus::Approved ? now() : null,
            'existence_status' => ExistenceStatus::Active, 'existence_checked_at' => now(),
        ]);
        $user->biz_no = $biz;
        $user->save();

        return $user->fresh();
    }

    private function makeAdmin(): User
    {
        $admin = $this->member('1088612463', '01099990000');
        $admin->update(['role' => UserRole::Admin]);

        return $admin->fresh();
    }
}
