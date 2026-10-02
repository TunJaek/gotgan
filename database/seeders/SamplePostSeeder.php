<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\PostCondition;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Enums\VatMode;
use App\Enums\ExistenceStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 개발용 샘플 매물. 화면을 만들 때 볼 데이터가 필요해서 넣는다.
 *
 *   docker exec gotgan-laravel.test-1 php artisan db:seed --class=SamplePostSeeder
 */
class SamplePostSeeder extends Seeder
{
    public function run(): void
    {
        $user = $this->sampleUser();

        // 카테고리 번호는 2026_10_02_000100 마이그레이션 기준. 16 컬러 복합기, 20 정품 토너, 21 재생 토너, 24 용지, 25 정착기
        $samples = [
            [PostType::Sell, 16, '컬러 레이저 복합기 임대 회수분 정리', 450000, false, VatMode::Exclusive, PostCondition::Used,
                "임대 끝나고 회수한 A3 컬러 복합기입니다.\n5대 일괄이 원칙이고, 2대 단위로도 나눕니다.\n직접 인수 조건이며 상차는 도와드립니다."],
            [PostType::Sell, 21, '재생 토너 대량 (호환 기종 다수)', 18000, true, VatMode::Inclusive, PostCondition::New,
                "흑백 재생 토너 100개 있습니다.\n20개 이상부터 판매하고, 수량 많으면 단가 조정 가능합니다."],
            [PostType::Sell, 20, '정품 토너 미개봉 재고 정리', null, true, VatMode::Negotiable, PostCondition::Unopened,
                "기종 변경으로 남은 정품 토너입니다. 박스 미개봉입니다.\n가격은 수량 보고 협의하겠습니다."],
            [PostType::Sell, 25, '정착기(퓨저) 유닛 분해품', 90000, false, VatMode::Exclusive, PostCondition::Defect,
                "폐기기에서 분해한 정착기입니다. 일부 롤러 마모 있습니다.\n부품용으로 가져가실 분 찾습니다."],
            [PostType::Want, 24, 'A4 복사용지 대량 구합니다', null, false, null, null,
                "월 200박스 정도 꾸준히 필요합니다.\n장기 거래 가능한 곳 찾습니다."],
        ];

        foreach ($samples as [$type, $category, $title, $price, $negotiable, $vat, $condition, $description]) {
            Post::create([
                'user_idx' => $user->idx,
                'post_type' => $type,
                'category_id' => $category,
                'title' => $title,
                'description' => $description,
                'condition' => $condition,
                'status' => PostStatus::Selling,
                'is_hidden' => false,
                'photos' => [],     // 샘플에는 실제 파일이 없다. 사진 없음 화면 확인용
                'region_code' => '11590102',
                'region_name' => '서울 동작구 상도동',
                'price_amount' => $type === PostType::Sell ? $price : null,
                'price_negotiable' => $negotiable,
                'vat_mode' => $vat,
                'budget_amount' => $type === PostType::Want ? $price : null,
            ]);
        }

        $this->command->info('샘플 매물 '.count($samples).'건을 넣었습니다.');
    }

    /** 샘플 글을 붙일 승인된 회원. 없으면 만든다. */
    private function sampleUser(): User
    {
        if ($user = User::where('review_status', ApplicationStatus::Approved)->first()) {
            return $user;
        }

        $user = new User([
            'password' => 'Sample1234!',
            'phone' => '01000000000',
            'phone_verified_at' => now(),
            'name' => '김샘플',
            'company_name' => '샘플상사',
            'region_code' => '11590',
            'region_name' => '서울 동작구',
            'role' => UserRole::Member,
            'status' => UserStatus::Active,
            'review_status' => ApplicationStatus::Approved,
            'submitted_at' => now(),
            'approved_at' => now(),
            'existence_status' => ExistenceStatus::Active,
            'existence_checked_at' => now(),
        ]);
        $user->biz_no = '1048145271';
        $user->save();

        return $user;
    }
}
