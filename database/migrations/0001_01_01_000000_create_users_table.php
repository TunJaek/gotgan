<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 로그인 계정. 아이디는 사업자등록번호이며, 사업체 정보와 가입 심사도 여기에 같이 둔다.
        Schema::create('users', function (Blueprint $table) {
            $table->comment('로그인 계정. 사업체 1곳당 1계정');
            $table->increments('idx')->comment('계정 일련번호');
            $table->string('password')->comment('bcrypt 해시. 8~11자 대소문자+숫자+특수기호');
            $table->string('phone', 20)->comment('휴대폰 번호. 숫자만 저장');
            $table->dateTime('phone_verified_at')->nullable()->comment('휴대폰 인증 완료 시각');
            $table->string('email')->nullable()->comment('인증하지 않는 보조 연락처');
            $table->string('name')->comment('대표자명');
            $table->unsignedTinyInteger('role')->default(0)->comment('0=일반회원 / 1=운영자');
            $table->unsignedTinyInteger('status')->default(0)->comment('0=정상 / 1=이용정지');
            $table->string('suspended_reason')->nullable()->comment('이용정지 사유');
            $table->rememberToken();
            $table->datetimes();
        });

        // 비밀번호 재설정 토큰. 프레임워크 기본 테이블이라 컬럼명을 바꾸지 않는다.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->comment('비밀번호 재설정 토큰(프레임워크 기본)');
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTime('created_at')->nullable();
        });

        // 로그인 세션. 프레임워크가 컬럼명을 고정하므로 user_id 그대로 둔다.
        Schema::create('sessions', function (Blueprint $table) {
            $table->comment('로그인 세션(프레임워크 기본). user_id 는 users.idx 를 가리킨다');
            $table->string('id')->primary();
            $table->unsignedInteger('user_id')->nullable()->index()->comment('users.idx. 프레임워크 고정 컬럼명');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
