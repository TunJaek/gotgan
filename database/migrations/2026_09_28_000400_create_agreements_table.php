<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 누가 어느 문서의 몇 판에 언제 동의했는가. 약관이 바뀌어 다시 동의받으면 새 행이 생기고 이전 행은 남는다.
        Schema::create('agreements', function (Blueprint $table) {
            $table->comment('이용약관·개인정보처리방침 동의 기록. 덧붙이기만 한다');
            $table->increments('idx')->comment('동의 일련번호');
            $table->unsignedInteger('user_idx')->comment('동의한 users.idx');
            $table->string('document', 20)->comment('terms=이용약관 / privacy=개인정보처리방침');
            $table->unsignedSmallInteger('version')->comment('동의한 판 번호. resources/legal/{document}-v{version}.md');
            $table->dateTime('agreed_at')->comment('동의 시각');
            $table->datetimes();

            $table->foreign('user_idx')->references('idx')->on('users');
            $table->index(['user_idx', 'document']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreements');
    }
};
