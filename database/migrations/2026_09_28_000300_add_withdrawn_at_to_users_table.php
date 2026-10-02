<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 탈퇴해도 행은 지우지 않는다. 상대방의 대화·신고·심사 이력이 이 행을 가리킨다.
        // 대신 개인정보를 지우고(User::withdraw) status 를 2 로 바꾼다.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('status')->default(0)->comment('0=정상 / 1=이용정지 / 2=탈퇴')->change();
            $table->dateTime('withdrawn_at')->nullable()->after('suspended_reason')->comment('탈퇴 시각. 이때 개인정보를 지웠다');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('withdrawn_at');
            $table->unsignedTinyInteger('status')->default(0)->comment('0=정상 / 1=이용정지')->change();
        });
    }
};
