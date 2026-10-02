<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 매물 글. 수량·묶음·최소구매 같은 거래 조건은 컬럼으로 두지 않고 description 에 판매자가 직접 적는다.
        Schema::create('posts', function (Blueprint $table) {
            $table->comment('매물 글. 거래 조건은 컬럼이 아니라 description 에 자유 기술');
            $table->increments('idx')->comment('게시물 일련번호');
            $table->unsignedInteger('user_idx')->comment('작성자 users.idx');
            $table->unsignedTinyInteger('post_type')->comment('1=팝니다 / 2=삽니다. 생성 후 변경 불가');
            $table->unsignedTinyInteger('category_id')->comment('카테고리 코드(FK 아님, 코드값)');
            $table->string('title')->comment('제목');
            $table->text('description')->comment('설명. 수량·묶음·최소구매·인수 조건을 판매자가 직접 기술');
            $table->unsignedTinyInteger('condition')->nullable()
                ->comment('1=새것 / 2=미개봉 / 3=중고 / 4=하자');
            $table->unsignedTinyInteger('status')->default(0)
                ->comment('0=작성중 / 1=판매중 / 2=거래완료 / 3=판매중지 / 4=보관. 판매자가 직접 변경');
            $table->boolean('is_hidden')->default(false)->comment('운영자 숨김. status 와 별개로 동작');
            $table->string('region_code', 30)->comment('물품 소재 지역. 판매자 자유 입력');

            $table->unsignedBigInteger('price_amount')->nullable()->comment('판매가(원). NULL 이면 "가격 협의"');
            $table->boolean('price_negotiable')->default(false)->comment('금액 옆에 "협의 가능" 표시 여부');
            $table->unsignedTinyInteger('vat_mode')->nullable()
                ->comment('1=부가세 별도 / 2=포함 / 3=협의');

            $table->unsignedBigInteger('budget_amount')->nullable()->comment('WANT 전용 예산(원). NULL 이면 "예산 협의"');
            $table->date('listed_until')->nullable()->comment('WANT 전용 게시 마감일. 이 날짜까지만 노출');

            // 사진은 별도 테이블로 나누지 않고 여기 담는다. 한 글에 1~10장.
            // [{"key":"저장경로","alt":"대체텍스트"}, ...] 이며 첫 번째가 대표 사진이다.
            // 변환 실패 시 저장 자체를 롤백하므로 사진별 상태값은 두지 않는다.
            $table->json('photos')->comment('사진 목록. 첫 번째가 대표. 최대 10장');

            $table->datetimes();

            $table->foreign('user_idx')->references('idx')->on('users')->cascadeOnDelete();
            $table->index(['status', 'is_hidden', 'created_at']);
            $table->index(['post_type', 'category_id']);
            $table->index('region_code');
            $table->index('price_amount');
        });

        // 최소한의 방어선. 게시 시점 검증은 애플리케이션에서 한다.
        DB::statement('ALTER TABLE posts ADD CONSTRAINT chk_price CHECK (price_amount IS NULL OR price_amount >= 1)');

        // 매물 상태 변경 이력.
        Schema::create('post_status_logs', function (Blueprint $table) {
            $table->comment('매물 상태 변경 이력');
            $table->increments('idx')->comment('이력 일련번호');
            $table->unsignedInteger('post_idx')->comment('posts.idx');
            $table->string('old_status', 20)->nullable()->comment('변경 전 상태');
            $table->string('new_status', 20)->comment('변경 후 상태');
            $table->string('reason')->nullable()->comment('변경 사유');
            $table->unsignedInteger('actor_idx')->nullable()->comment('변경한 users.idx. 시스템 변경이면 NULL');
            $table->datetimes();

            $table->foreign('post_idx')->references('idx')->on('posts')->cascadeOnDelete();
            $table->foreign('actor_idx')->references('idx')->on('users');
        });

        // 찜. 사업체와 매물의 조합이 곧 기본키라 별도 일련번호를 두지 않는다.
        Schema::create('favorites', function (Blueprint $table) {
            $table->comment('찜. (사업체+매물) 조합이 기본키');
            $table->unsignedInteger('user_idx')->comment('찜한 users.idx');
            $table->unsignedInteger('post_idx')->comment('찜한 매물 posts.idx');
            $table->datetime('created_at')->comment('찜한 시각');

            $table->foreign('user_idx')->references('idx')->on('users')->cascadeOnDelete();
            $table->foreign('post_idx')->references('idx')->on('posts')->cascadeOnDelete();
            $table->primary(['user_idx', 'post_idx']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('post_status_logs');
        Schema::dropIfExists('posts');
    }
};
