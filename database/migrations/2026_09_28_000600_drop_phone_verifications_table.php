<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 휴대폰 확인을 문자 인증번호에서 다날 본인인증(PASS)으로 바꿨다(2026-09-28). 인증번호 기록은 더 쓰지 않는다.
    public function up(): void
    {
        Schema::dropIfExists('phone_verifications');
    }

    public function down(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->comment('휴대폰 인증번호. 가입 시 1회 사용 후 삭제');
            $table->increments('idx')->comment('인증 일련번호');
            $table->string('phone', 20)->index()->comment('인증 대상 휴대폰 번호. 숫자만');
            $table->char('code_hash', 64)->comment('인증번호 6자리의 해시. 원문은 저장하지 않음');
            $table->dateTime('expires_at')->comment('만료 시각(발송 후 3분)');
            $table->dateTime('verified_at')->nullable()->comment('인증 성공 시각. NULL 이면 미인증');
            $table->unsignedTinyInteger('attempt_count')->default(0)->comment('입력 시도 횟수. 5회 초과 시 차단');
            $table->datetimes();
        });
    }
};
