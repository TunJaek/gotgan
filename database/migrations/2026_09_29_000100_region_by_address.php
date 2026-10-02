<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 지역을 주소 검색으로 받는다(2026-09-29). 법정동 코드 앞자리와 고른 순간의 이름만 저장한다.
 * 회원 사업장은 시군구 5자리, 매물 소재지는 읍면동 8자리. 지역 목록 표는 두지 않는다(app/Support/PicksRegion.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users' => '사업장 지역 이름. 예) 서울 동작구', 'posts' => '물품 소재 지역 이름. 예) 서울 동작구 상도동'] as $table => $comment) {
            Schema::table($table, function (Blueprint $t) use ($comment) {
                $t->string('region_code', 30)->nullable()->change();   // 옮기는 동안 NULL 을 받게 먼저 푼다
                $t->string('region_name', 40)->nullable()->after('region_code')->comment($comment);
            });

            // 지금까지 region_code 에 '경기 성남시' 같은 글자가 들어 있었다(출시 전 샘플). 이름 칸으로 옮기고 코드는 비운다.
            DB::table($table)->whereNotNull('region_code')->whereRaw("region_code NOT REGEXP '^[0-9]+$'")
                ->update(['region_name' => DB::raw('region_code'), 'region_code' => null]);
            DB::table($table)->where('region_code', '0000')->update(['region_code' => null]);
        }

        Schema::table('users', fn (Blueprint $t) => $t->string('region_code', 5)->nullable()
            ->comment('사업장 지역. 법정동 코드 앞 5자리(시군구). NULL 이면 미설정')->change());
        Schema::table('posts', fn (Blueprint $t) => $t->string('region_code', 8)->nullable()
            ->comment('물품 소재 지역. 법정동 코드 앞 8자리(읍면동). 앞 5자리가 시군구, 앞 2자리가 시도. 임시저장 글은 NULL')->change());
    }

    public function down(): void
    {
        foreach (['users' => 10, 'posts' => 30] as $table => $length) {
            Schema::table($table, fn (Blueprint $t) => $t->string('region_code', 30)->nullable()->change());
            DB::table($table)->update(['region_code' => DB::raw("LEFT(COALESCE(region_name, region_code, '0000'), $length)")]);
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('region_name'));
        }

        Schema::table('users', fn (Blueprint $t) => $t->string('region_code', 10)->nullable(false)->comment('지역코드(시도+시군구)')->change());
        Schema::table('posts', fn (Blueprint $t) => $t->string('region_code', 30)->nullable(false)->comment('물품 소재 지역. 판매자 자유 입력')->change());
    }
};
