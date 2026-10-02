<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\ExistenceStatus;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AccountPostsTest extends TestCase
{
    use RefreshDatabase;

    public function test_내_글만_상태와_관계없이_보이고_칩으로_나뉜다(): void
    {
        $me = $this->member('01011112222', '123-45-67891');
        $other = $this->member('01033334444', '220-81-62517');
        $this->makePost($me, '판매중 글');
        $this->makePost($me, '완료된 글', ['status' => PostStatus::Done]);
        $this->makePost($me, '중지한 글', ['status' => PostStatus::Stopped]);
        $this->makePost($other, '남의 글');

        Livewire::actingAs($me)->test('account.posts')
            ->assertSee(['판매중 글', '완료된 글', '중지한 글', '3건'])
            ->assertDontSee('남의 글')
            ->set('status', 'done')
            ->assertSee('완료된 글')
            ->assertDontSee(['판매중 글', '중지한 글']);
    }

    public function test_숨김과_마감은_이유를_알려주고_작성중은_이어_쓰기로_보낸다(): void
    {
        $me = $this->member('01011112222', '123-45-67891');
        $hidden = $this->makePost($me, '숨겨진 글', ['is_hidden' => true]);
        $this->makePost($me, '마감된 글', ['post_type' => PostType::Want, 'condition' => null, 'listed_until' => today()->subDay()]);
        $this->makePost($me, '', ['post_type' => PostType::Want, 'condition' => null, 'status' => PostStatus::Draft]);

        Livewire::actingAs($me)->test('account.posts')
            ->assertSee('운영자가 숨김 처리한 글입니다')
            ->assertDontSee(route('posts.edit', $hidden))          // 숨긴 글은 상세·수정이 404 라 링크가 없다
            ->assertSee('게시 마감일('.today()->subDay()->format('Y-m-d').')')
            ->assertSee('제목 없음')
            ->assertSee(route('posts.create').'?type=want');       // 구해요 작성중 글은 구해요 등록 화면으로
    }

    public function test_관심_매물은_볼_수_없게_된_글도_남고_해제할_수_있다(): void
    {
        $me = $this->member('01011112222', '123-45-67891');
        $seller = $this->member('01033334444', '220-81-62517');
        $alive = $this->makePost($seller, '살아 있는 글');
        $hidden = $this->makePost($seller, '숨겨진 글', ['is_hidden' => true]);
        $me->favoritePosts()->attach([$alive->idx, $hidden->idx]);

        Livewire::actingAs($me)->test('account.posts', ['tab' => 'favorites'])
            ->assertSee(['살아 있는 글', '더 이상 볼 수 없는 글입니다'])
            ->assertSee(route('posts.detail', $alive))
            ->assertDontSee(route('posts.detail', $hidden))
            ->call('unfavorite', $hidden->idx)
            ->assertDontSee('더 이상 볼 수 없는 글입니다')
            ->assertSee('1건');
    }

    public function test_내_정보에서_들어갈_수_있다(): void
    {
        $me = $this->member('01011112222', '123-45-67891');

        $this->actingAs($me)->get(route('account.index'))
            ->assertSee(route('account.posts'))
            ->assertDontSee('가입 심사 현황');                // 승인된 회원에게는 심사 현황을 숨긴다
        $this->actingAs($me)->get(route('account.posts'))->assertOk();
        auth()->logout();
        $this->get(route('account.posts'))->assertRedirect(route('login'));
    }

    public function test_승인_전에는_심사_현황을_보여준다(): void
    {
        $me = $this->member('01011112222', '123-45-67891');
        $me->update(['review_status' => \App\Enums\ApplicationStatus::Reviewing]);

        $this->actingAs($me)->get(route('account.index'))
            ->assertSee(['가입 심사 현황', '관리자가 사업자등록증을 확인하고 있습니다'])
            ->assertDontSee(route('account.posts'));
    }

    private function makePost(User $user, string $title, array $overrides = []): Post
    {
        return Post::create($overrides + [
            'user_idx' => $user->idx, 'post_type' => PostType::Sell, 'category_id' => 15, 'title' => $title,
            'description' => '설명', 'condition' => 1, 'status' => PostStatus::Selling, 'is_hidden' => false,
            'price_amount' => 10000, 'region_code' => '11590102', 'region_name' => '서울 동작구 상도동', 'photos' => [],
        ]);
    }

    private function member(string $phone, string $biz_no): User
    {
        $user = new User([
            'password' => 'Abcd123!', 'phone' => $phone, 'phone_verified_at' => now(),
            'name' => '홍길동', 'company_name' => '회원'.$phone, 'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => UserRole::Member, 'status' => UserStatus::Active, 'review_status' => ApplicationStatus::Approved,
            'submitted_at' => now(), 'approved_at' => now(),
            'existence_status' => ExistenceStatus::Active, 'existence_checked_at' => now(),
        ]);
        $user->biz_no = $biz_no;
        $user->save();

        return $user;
    }
}
