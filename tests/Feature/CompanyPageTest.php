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

class CompanyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_업체의_팝니다_구해요_글을_탭으로_보고_거래_중인_글만_나온다(): void
    {
        $seller = $this->company('01012345678', '123-45-67891');
        $other = $this->company('01099998888', '789-45-67891');
        $this->makePost($seller, '파는 박스');
        $this->makePost($seller, '구하는 테이프', ['post_type' => PostType::Want, 'condition' => null]);
        $this->makePost($seller, '다 팔린 박스', ['status' => PostStatus::Done]);
        $this->makePost($seller, '숨긴 박스', ['is_hidden' => true]);
        $this->makePost($other, '다른 업체 박스');

        Livewire::test('companies.show', ['user' => $seller])
            ->assertSee(['대성포장산업', '사업자 서류 확인', '팝니다 1', '구해요 1', '파는 박스'])
            ->assertDontSee(['구하는 테이프', '다 팔린 박스', '숨긴 박스', '다른 업체 박스'])
            ->set('type', PostType::Want->value)
            ->assertSee('구하는 테이프')
            ->assertDontSee('파는 박스');
    }

    public function test_비회원도_볼_수_있고_탈퇴_업체는_404다(): void
    {
        $seller = $this->company('01012345678', '123-45-67891');

        $this->get(route('companies.show', $seller))->assertOk();

        $seller->forceFill(['status' => UserStatus::Withdrawn])->save();
        $this->get(route('companies.show', $seller))->assertNotFound();
    }

    public function test_상세의_업체명과_전체_보기가_업체_페이지로_간다(): void
    {
        $seller = $this->company('01012345678', '123-45-67891');
        $post = $this->makePost($seller, '파는 박스');

        Livewire::test('posts.detail', ['post' => $post])
            ->assertSee(route('companies.show', $seller))
            ->assertSee('이 업체의 글 전체 보기');
    }

    private function makePost(User $user, string $title, array $overrides = []): Post
    {
        return Post::create($overrides + [
            'user_idx' => $user->idx, 'post_type' => PostType::Sell, 'category_id' => 15, 'title' => $title,
            'description' => '설명', 'condition' => 1, 'status' => PostStatus::Selling, 'is_hidden' => false,
            'price_amount' => 10000, 'region_code' => '11590102', 'region_name' => '서울 동작구 상도동', 'photos' => [],
        ]);
    }

    private function company(string $phone, string $number): User
    {
        $user = new User([
            'password' => 'Abcd123!', 'phone' => $phone, 'phone_verified_at' => now(),
            'name' => '홍길동', 'company_name' => '대성포장산업', 'region_code' => '11590', 'region_name' => '서울 동작구',
            'role' => UserRole::Member, 'status' => UserStatus::Active, 'review_status' => ApplicationStatus::Approved,
            'submitted_at' => now(), 'approved_at' => now(),
            'existence_status' => ExistenceStatus::Active, 'existence_checked_at' => now(),
        ]);
        $user->biz_no = $number;
        $user->save();

        return $user;
    }
}
