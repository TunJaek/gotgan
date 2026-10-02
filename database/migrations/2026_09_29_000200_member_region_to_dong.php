<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 회원 사업장 지역을 읍면동까지 받는다(2026-09-29). 재고 등록 때 기본 주소로 쓴다.
 * 이미 5자리(시군구)로 저장된 회원은 그대로 두고, 정보 수정에서 주소를 다시 고르면 8자리가 된다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('region_code', 8)->nullable()
            ->comment('사업장 지역. 법정동 코드 앞 8자리(읍면동). 예전 가입자는 5자리(시군구)일 수 있다. NULL 이면 미설정')->change());
        Schema::table('users', fn (Blueprint $t) => $t->string('region_name', 40)->nullable()
            ->comment('사업장 지역 이름. 예) 서울 동작구 상도동')->change());
    }

    public function down(): void
    {
        // 8자리는 앞 5자리(시군구)로 줄이고 이름에서 동을 뗀다
        \Illuminate\Support\Facades\DB::table('users')->whereRaw('CHAR_LENGTH(region_code) = 8')->update([
            'region_code' => \Illuminate\Support\Facades\DB::raw('LEFT(region_code, 5)'),
            'region_name' => \Illuminate\Support\Facades\DB::raw("SUBSTRING(region_name, 1, CHAR_LENGTH(region_name) - CHAR_LENGTH(SUBSTRING_INDEX(region_name, ' ', -1)) - 1)"),
        ]);
        Schema::table('users', fn (Blueprint $t) => $t->string('region_code', 5)->nullable()
            ->comment('사업장 지역. 법정동 코드 앞 5자리(시군구). NULL 이면 미설정')->change());
    }
};
