<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 휴대폰 본인인증(다날 PASS) 결과. 이름·생년월일·원본 CI 는 저장하지 않는다.
        Schema::table('users', function (Blueprint $table) {
            $table->char('ci_hash', 64)->nullable()->after('phone_verified_at')
                ->comment('본인인증 CI 의 HMAC-SHA256(APP_KEY). 비밀번호 찾기·휴대폰 변경 때 같은 사람인지 본다. 유일하지 않다');
            $table->dateTime('identity_verified_at')->nullable()->after('ci_hash')->comment('본인인증 시각');
            $table->index('ci_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['ci_hash']);
            $table->dropColumn(['ci_hash', 'identity_verified_at']);
        });
    }
};
