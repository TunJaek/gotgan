<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ContactConsent;
use App\Models\Inquiry;
use App\Models\Post;
use App\Models\User;
use App\Support\Moderation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WithdrawTest extends TestCase
{
    use RefreshDatabase;

    public function test_탈퇴하면_개인정보가_지워지고_매물이_숨겨진다(): void
    {
        Storage::fake('local');
        $user = $this->approvedUser();
        $buyer = $this->approvedUser('01099998888', '789-45-67891');

        Storage::disk('local')->put($user->licenseKey(), '등록증');
        $post = $this->postOf($user, show_phone: true);
        ContactConsent::syncFor($post);
        $user->favoritePosts()->attach($this->postOf($buyer)->idx);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->idx, 'payload' => '', 'last_activity' => time()]);

        Livewire::actingAs($user)
            ->test('account.withdraw')
            ->set('password', 'Abcd123!')
            ->set('confirmed', true)
            ->call('withdraw')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $gone = $user->fresh();
        $this->assertSame(UserStatus::Withdrawn, $gone->status);
        $this->assertNotNull($gone->withdrawn_at);
        $this->assertSame('탈퇴한 회원', $gone->company_name);
        $this->assertSame('', $gone->phone);
        $this->assertNull($gone->email);
        $this->assertFalse(Storage::disk('local')->exists($user->licenseKey()));

        // 매물은 숨기고 연락처 공개는 끄고 동의에 철회를 찍는다
        $this->assertTrue($post->fresh()->is_hidden);
        $this->assertFalse($post->fresh()->show_phone);
        $this->assertNotNull(ContactConsent::first()->withdrawn_at);
        $this->assertSame(1, AuditLog::where('action', 'post.hidden.by_withdraw')->count());

        $this->assertSame(0, DB::table('favorites')->where('user_idx', $user->idx)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->idx)->count());

        // 같은 사업자번호가 비었다 — 다시 가입할 수 있다
        $this->assertFalse(User::where('biz_no', '1234567891')->exists());

        // 탈퇴 뒤 정지를 걸어도 상태가 바뀌지 않는다
        Moderation::suspendUser($gone, '탈퇴 후 신고 접수');
        $this->assertSame(UserStatus::Withdrawn, $gone->fresh()->status);
    }

    public function test_탈퇴하기를_누르면_먼저_확인_모달이_뜨고_취소할_수_있다(): void
    {
        $user = $this->approvedUser();

        $c = Livewire::actingAs($user)->test('account.withdraw')
            ->set('password', 'Wrong123!')
            ->set('confirmed', true)
            ->call('ask')
            ->assertHasErrors('password')
            ->assertSet('asking', false)                    // 비밀번호가 틀리면 모달을 열지 않는다
            ->set('password', 'Abcd123!')
            ->call('ask')
            ->assertHasNoErrors()
            ->assertSet('asking', true)
            ->assertSee('정말 탈퇴하시겠습니까?');
        $this->assertSame(UserStatus::Active, $user->fresh()->status);   // 아직 탈퇴 전

        $c->call('cancel')->assertSet('asking', false)->assertDontSee('정말 탈퇴하시겠습니까?');
        $this->assertSame(UserStatus::Active, $user->fresh()->status);

        $c->call('ask')->call('withdraw')->assertRedirect(route('login'));
        $this->assertSame(UserStatus::Withdrawn, $user->fresh()->status);
    }

    public function test_비밀번호가_틀리면_탈퇴하지_않는다(): void
    {
        $user = $this->approvedUser();

        Livewire::actingAs($user)
            ->test('account.withdraw')
            ->set('password', 'Wrong123!')
            ->set('confirmed', true)
            ->call('withdraw')
            ->assertHasErrors('password');

        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }

    public function test_이용정지_회원과_관리자는_탈퇴할_수_없다(): void
    {
        $suspended = $this->approvedUser();
        $suspended->update(['status' => UserStatus::Suspended]);

        Livewire::actingAs($suspended->fresh())
            ->test('account.withdraw')
            ->set('password', 'Abcd123!')
            ->set('confirmed', true)
            ->call('withdraw')
            ->assertHasErrors('password');
        $this->assertSame(UserStatus::Suspended, $suspended->fresh()->status);

        $admin = $this->approvedUser('01011112222', '211-88-12346');
        $admin->update(['role' => UserRole::Admin]);

        $this->actingAs($admin->fresh())->get(route('account.withdraw'))->assertForbidden();
    }

    public function test_탈퇴한_회원에게는_메시지를_보낼_수_없다(): void
    {
        $user = $this->approvedUser();
        $buyer = $this->approvedUser('01099998888', '789-45-67891');
        $room = Inquiry::between($buyer, $user);

        $user->withdraw();

        Livewire::actingAs($buyer)
            ->test('inquiries.chat', ['room' => $room])
            ->assertSee('탈퇴한 회원')
            ->assertSee('미설정')             // 탈퇴하면 지역이 비워진다(NULL)
            ->set('draft', '아직 재고 있나요?')
            ->call('send')
            ->assertHasErrors('draft');
    }

    public function test_관리자도_탈퇴_회원의_매물_숨김은_풀_수_없다(): void
    {
        $user = $this->approvedUser();
        $post = $this->postOf($user);
        $admin = $this->approvedUser('01011112222', '211-88-12346');
        $admin->update(['role' => UserRole::Admin]);

        $user->withdraw();

        Livewire::actingAs($admin->fresh())
            ->test('admin.post', ['post' => $post->idx])
            ->set('reason', '신고가 잘못 들어왔음')
            ->call('unhide')
            ->assertHasErrors('reason');

        // 화면을 거치지 않고 불러도 되살리지 않는다
        Moderation::unhidePost($post->fresh(), '직접 호출');

        $this->assertTrue($post->fresh()->is_hidden);
    }

    private function postOf(User $user, bool $show_phone = false): Post
    {
        return Post::create([
            'user_idx' => $user->idx,
            'post_type' => PostType::Sell,
            'category_id' => 15,
            'title' => '탈퇴 확인용 매물',
            'description' => '설명입니다.',
            'condition' => 1,
            'status' => PostStatus::Selling,
            'is_hidden' => false,
            'region_code' => '11590102', 'region_name' => '서울 동작구 상도동',
            'show_phone' => $show_phone,
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
