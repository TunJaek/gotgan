<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 판매자 휴대폰 번호를 다른 회원에게 보여 주는 것은 개인정보 제3자 제공이다. 매물마다 선택 동의를 받고 남긴다.
        // 동의할 때 한 행이 생기고, 비공개로 바꾸면 그 행에 철회 시각이 찍힌다. 다시 공개하면 새 행이다.
        Schema::create('contact_consents', function (Blueprint $table) {
            $table->comment('매물 연락처 공개(제3자 제공) 동의 기록. 동의·철회 이력을 지우지 않는다');
            $table->increments('idx')->comment('동의 일련번호');
            $table->unsignedInteger('post_idx')->comment('공개한 posts.idx');
            $table->unsignedInteger('user_idx')->comment('동의한 판매자 users.idx. 공개되는 번호의 주인');
            $table->unsignedSmallInteger('version')->comment('동의 문구 버전. ContactConsent::VERSION');
            $table->dateTime('agreed_at')->comment('동의 시각');
            $table->dateTime('withdrawn_at')->nullable()->comment('철회 시각. NULL 이면 지금 공개 중');
            $table->datetimes();

            $table->foreign('post_idx')->references('idx')->on('posts');
            $table->foreign('user_idx')->references('idx')->on('users');
            $table->index(['post_idx', 'withdrawn_at']);
        });

        // 지금까지는 동의 없이 공개가 기본이었다. 동의 기록이 없으니 전부 비공개로 돌린다(2026-09-28 결정).
        DB::table('posts')->update(['show_phone' => false]);

        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('show_phone')->default(false)
                ->comment('판매자 휴대폰 번호 공개 여부. 켜려면 contact_consents 에 동의가 있어야 한다')->change();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('show_phone')->default(true)->comment('판매자 휴대폰 번호 공개 여부')->change();
        });

        Schema::dropIfExists('contact_consents');
    }
};
