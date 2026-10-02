<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ContactConsent;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ContactConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_기본은_비공개이고_동의_없이는_공개로_등록되지_않는다(): void
    {
        $user = $this->approvedUser();

        $this->fill(Livewire::actingAs($user)->test('posts.create'))
            ->assertSet('show_phone', false)
            ->set('show_phone', '1')
            ->call('save')
            ->assertHasErrors('phone_consent');

        // 자동저장된 임시글도 공개로 남지 않는다
        $this->assertSame(0, Post::where('show_phone', true)->count());
        $this->assertSame(0, ContactConsent::count());
    }

    public function test_동의하면_공개되고_동의_기록이_남는다(): void
    {
        $user = $this->approvedUser();

        $this->fill(Livewire::actingAs($user)->test('posts.create'))
            ->set('show_phone', '1')
            ->set('phone_consent', true)
            ->call('save')
            ->assertHasNoErrors();

        $post = Post::first();
        $consent = ContactConsent::first();

        $this->assertTrue($post->show_phone);
        $this->assertSame($post->idx, $consent->post_idx);
        $this->assertSame($user->idx, $consent->user_idx);
        $this->assertSame(ContactConsent::VERSION, $consent->version);
        $this->assertNull($consent->withdrawn_at);
    }

    public function test_비공개로_바꾸면_철회가_찍히고_다시_공개하면_새로_동의한다(): void
    {
        $user = $this->approvedUser();

        $this->fill(Livewire::actingAs($user)->test('posts.create'))
            ->set('show_phone', '1')
            ->set('phone_consent', true)
            ->call('save');
        $post = Post::first();

        // 이미 동의한 글은 수정 화면에서 체크된 채로 열린다. 단순 수정에는 다시 묻지 않는다.
        Livewire::actingAs($user)->test('posts.create', ['post' => $post])
            ->assertSet('phone_consent', true)
            ->set('title', '제목만 고친 골판지 상자')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(1, ContactConsent::count());

        Livewire::actingAs($user)->test('posts.create', ['post' => $post->fresh()])
            ->set('show_phone', '0')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($post->fresh()->show_phone);
        $this->assertNotNull(ContactConsent::first()->withdrawn_at);

        Livewire::actingAs($user)->test('posts.create', ['post' => $post->fresh()])
            ->assertSet('phone_consent', false)
            ->set('show_phone', '1')
            ->set('phone_consent', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, ContactConsent::count());
        $this->assertSame(1, ContactConsent::whereNull('withdrawn_at')->count());
    }

    private function fill($component)
    {
        Storage::fake('local');

        return $component
            ->set('photos', [UploadedFile::fake()->image('a.jpg')])
            ->set('title', '골판지 상자 3호 500장')
            ->set('description', '창고 정리 물량입니다.')
            ->set('condition', 1)
            ->set('region_code', '11590102')->set('region_name', '서울 동작구 상도동')
            ->set('price_amount', 50000);
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
