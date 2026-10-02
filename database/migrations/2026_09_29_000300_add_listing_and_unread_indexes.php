<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 데이터가 쌓였을 때 느려질 쿼리 두 개를 인덱스로 받친다(2026-09-29 점검).
 *
 *   messages (read_at, inquiry_idx)
 *     헤더의 '안 읽은 문의 수'가 로그인한 모든 페이지에서 돈다. MySQL 은 '안 읽은 메시지를 먼저 모아 방과 맞추는' 식으로 풀어서
 *     read_at 이 앞에 와야 한다. 없으면 messages 를 통째로 읽는다(5만 개로 확인, 2026-09-29).
 *   messages (inquiry_idx, read_at)
 *     문의함 목록의 방별 안 읽은 수(withCount)와 대화방 읽음 처리용.
 *
 *   posts (status, is_hidden, post_type, created_at)  ← 기존 (status, is_hidden, created_at) 을 바꾼다
 *     장터 목록은 팝니다/구해요(post_type)를 늘 같이 거른다. 앞 두 칸이 같아서 기존 인덱스를 쓰던 쿼리도 그대로 쓴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['inquiry_idx', 'read_at']);
            $table->index(['read_at', 'inquiry_idx']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->index(['status', 'is_hidden', 'post_type', 'created_at'], 'posts_listing_index');
            $table->dropIndex(['status', 'is_hidden', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->index(['status', 'is_hidden', 'created_at']);
            $table->dropIndex('posts_listing_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['read_at', 'inquiry_idx']);
            $table->dropIndex(['inquiry_idx', 'read_at']);
        });
    }
};
