<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 대·중·소분류를 한 테이블에 둔다. 중분류는 parent_idx 에 대분류 번호, 소분류는 중분류 번호.
        // 카테고리를 더할 때는 이 파일을 고치지 말고 새 마이그레이션에서 insert 한다.
        Schema::create('categories', function (Blueprint $table) {
            $table->comment('매물 카테고리. parent_idx 로 최대 3단계');
            $table->smallIncrements('idx')->comment('카테고리 번호. posts.category_id 가 가리킨다');
            $table->unsignedSmallInteger('parent_idx')->nullable()->comment('상위 categories.idx. 대분류는 NULL');
            $table->string('name', 50)->comment('이름');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('같은 단계 안에서의 순서');
            $table->boolean('is_active')->default(true)->comment('0 이면 새 글에서 고를 수 없음. 기존 글 표시는 유지');
            $table->datetimes();

            $table->foreign('parent_idx')->references('idx')->on('categories');
        });

        $now = now();
        DB::table('categories')->insert(array_map(fn ($row) => $row + [
            'parent_idx' => null, 'created_at' => $now, 'updated_at' => $now,
        ], [
            ['idx' => 1, 'name' => '포장재', 'sort_order' => 1, 'is_active' => true],
            ['idx' => 2, 'name' => '원자재', 'sort_order' => 2, 'is_active' => true],
            ['idx' => 3, 'name' => '부품', 'sort_order' => 3, 'is_active' => true],
            ['idx' => 4, 'name' => '기계·공구', 'sort_order' => 4, 'is_active' => true],
            ['idx' => 5, 'name' => '건축·인테리어 자재', 'sort_order' => 5, 'is_active' => true],
            ['idx' => 6, 'name' => '사무·매장 비품', 'sort_order' => 6, 'is_active' => true],
            // 식품은 인허가 품목이라 받지 않는다(2026-09-28). 예전 글이 깨지지 않게 행은 남긴다.
            ['idx' => 8, 'name' => '식자재', 'sort_order' => 8, 'is_active' => false],
            ['idx' => 9, 'name' => '기타', 'sort_order' => 99, 'is_active' => true],
        ]));

        // 예전 코드값(config/gotgan.php)을 새 번호로 옮긴다. 한 문장이라 번호끼리 꼬이지 않는다.
        // 1 포장재·부자재→1, 2 원자재→2, 3 사무·비품→6, 4 기계·공구→4, 5 식자재→8, 그 밖(설정에 없던 6 등)→9 기타
        DB::table('posts')->update(['category_id' => DB::raw(
            'CASE category_id WHEN 1 THEN 1 WHEN 2 THEN 2 WHEN 3 THEN 6 WHEN 4 THEN 4 WHEN 5 THEN 8 ELSE 9 END'
        )]);

        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedSmallInteger('category_id')->comment('categories.idx')->change();
            $table->foreign('category_id')->references('idx')->on('categories');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
        });

        // 되돌릴 때는 예전 코드값으로. 부품·건축 자재는 예전에 없던 칸이라 기타로 간다.
        DB::table('posts')->update(['category_id' => DB::raw(
            'CASE category_id WHEN 1 THEN 1 WHEN 2 THEN 2 WHEN 6 THEN 3 WHEN 4 THEN 4 WHEN 8 THEN 5 ELSE 9 END'
        )]);

        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedTinyInteger('category_id')->comment('카테고리 코드(FK 아님, 코드값)')->change();
        });

        Schema::dropIfExists('categories');
    }
};
