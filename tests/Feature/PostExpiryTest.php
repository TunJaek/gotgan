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

class PostExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_마감일이_지난_글은_목록에서_빠진다(): void
    {
        $user = $this->approvedUser();
        $this->wantPost($user, '아직 모집중', today()->addDay());
        $this->wantPost($user, '오늘까지', today());
        $this->wantPost($user, '어제 끝남', today()->subDay());
        $this->wantPost($user, '마감일 없음', null);

        Livewire::test('posts.index', ['type' => PostType::Want->value])
            ->assertSee('아직 모집중')
            ->assertSee('오늘까지')          // 마감일 당일까지는 보인다
            ->assertSee('마감일 없음')
            ->assertDontSee('어제 끝남');
    }

    public function test_마감된_글의_상세는_남에게_404다(): void
    {
        $owner = $this->approvedUser();
        $post = $this->wantPost($owner, '어제 끝남', today()->subDay());

        $this->get(route('posts.detail', $post))->assertNotFound();

        $this->actingAs($this->approvedUser('01099998888', '789-45-67891'))
            ->get(route('posts.detail', $post))
            ->assertNotFound();
    }

    public function test_마감돼도_글쓴이는_볼_수_있다(): void
    {
        $owner = $this->approvedUser();
        $post = $this->wantPost($owner, '어제 끝남', today()->subDay());

        $this->actingAs($owner)
            ->get(route('posts.detail', $post))
            ->assertOk()
            ->assertSee('다른 사람에게는 보이지 않습니다', false);
    }

    private function wantPost(User $user, string $title, ?\Illuminate\Support\Carbon $until): Post
    {
        return Post::create([
            'user_idx' => $user->idx,
            'post_type' => PostType::Want,
            'category_id' => 15,
            'title' => $title,
            'description' => '설명입니다.',
            'status' => PostStatus::Selling,
            'is_hidden' => false,
            'region_code' => '11590102', 'region_name' => '서울 동작구 상도동',
            'listed_until' => $until,
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
