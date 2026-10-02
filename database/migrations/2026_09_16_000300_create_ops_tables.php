<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 문의 대화방. 사람 한 쌍이 한 방을 쓴다. 어느 매물 얘기인지는 메시지가 들고 있다.
        Schema::create('inquiries', function (Blueprint $table) {
            $table->comment('문의 대화방. 사람 한 쌍당 하나');
            $table->increments('idx')->comment('문의 일련번호');
            $table->unsignedInteger('from_user_idx')->comment('먼저 말을 건 users.idx');
            $table->unsignedInteger('to_user_idx')->comment('상대 users.idx');
            $table->unsignedTinyInteger('status')->default(0)->comment('0=진행중 / 1=종료');
            $table->dateTime('last_message_at')->nullable()->comment('마지막 메시지 시각. 문의함 정렬용');
            $table->datetimes();

            $table->foreign('from_user_idx')->references('idx')->on('users');
            $table->foreign('to_user_idx')->references('idx')->on('users');
            $table->index(['to_user_idx', 'last_message_at']);
            $table->index(['from_user_idx', 'last_message_at']);

            // 같은 방이 두 번 만들어지는 것을 막는다.
            // 단 (A,B) 와 (B,A) 는 DB 가 다른 조합으로 보므로, 방을 찾을 때는
            // 양방향으로 찾아야 한다 — Inquiry::between() 한 군데에서만 한다.
            $table->unique(['from_user_idx', 'to_user_idx']);
        });

        // 문의 대화 메시지.
        Schema::create('messages', function (Blueprint $table) {
            $table->comment('문의 대화 메시지');
            $table->increments('idx')->comment('메시지 일련번호');
            $table->unsignedInteger('inquiry_idx')->comment('inquiries.idx');
            $table->unsignedInteger('sender_idx')->comment('보낸 사람 users.idx');
            $table->unsignedInteger('post_idx')->nullable()
                ->comment('이 메시지가 어느 매물 얘기로 시작했는지. 일반 대화는 NULL');
            $table->json('terms_snapshot')->nullable()
                ->comment('문의 시점의 가격·조건 사본. 글이 바뀌어도 대화 맥락이 남는다');
            $table->text('body')->comment('메시지 본문');
            $table->dateTime('read_at')->nullable()->comment('상대가 읽은 시각. NULL 이면 미읽음');
            $table->datetimes();

            $table->foreign('inquiry_idx')->references('idx')->on('inquiries')->cascadeOnDelete();
            $table->foreign('sender_idx')->references('idx')->on('users');
            // 매물이 지워져도 대화는 남아야 한다. cascade 면 메시지까지 같이 사라진다.
            $table->foreign('post_idx')->references('idx')->on('posts')->nullOnDelete();
            $table->index(['inquiry_idx', 'created_at']);
        });

        // 연락처 열람 기록. 연락처 값 자체는 남기지 않고 누가 언제 봤는지만 남긴다.
        Schema::create('contact_access_logs', function (Blueprint $table) {
            $table->comment('연락처 열람 기록. 연락처 값은 저장하지 않고 열람 사실만 남긴다');
            $table->increments('idx')->comment('열람 일련번호');
            $table->unsignedInteger('viewer_user_idx')->comment('열람한 users.idx');
            $table->unsignedInteger('post_idx')->comment('열람 대상 posts.idx');
            $table->dateTime('accessed_at')->comment('열람 시각');
            $table->datetimes();

            $table->foreign('viewer_user_idx')->references('idx')->on('users');
            $table->foreign('post_idx')->references('idx')->on('posts');
            $table->index(['post_idx', 'accessed_at']);
        });

        // 신고. target_type 에 따라 target_idx 가 가리키는 테이블이 달라져 FK 를 걸지 않는다.
        Schema::create('reports', function (Blueprint $table) {
            $table->comment('신고. target_type 에 따라 대상 테이블이 달라져 FK 없음');
            $table->increments('idx')->comment('신고 일련번호');
            $table->unsignedInteger('reporter_idx')->comment('신고한 users.idx');
            $table->unsignedTinyInteger('target_type')
                ->comment('신고 대상 종류. 1=매물(posts) / 2=회원(users) / 3=메시지(messages)');
            $table->unsignedInteger('target_idx')->comment('신고 대상의 idx. target_type 과 함께 봐야 한다(FK 없음)');
            $table->text('reason')->comment('신고 사유');
            $table->unsignedTinyInteger('status')->default(0)
                ->comment('0=접수 / 1=확인중 / 2=조치완료 / 3=기각');
            $table->datetimes();

            $table->foreign('reporter_idx')->references('idx')->on('users');
            $table->index(['target_type', 'target_idx']);
            $table->index('status');
        });

        // 차단. 차단한 쪽과 당한 쪽의 조합이 곧 기본키다.
        Schema::create('blocks', function (Blueprint $table) {
            $table->comment('사업체 간 차단. (차단한쪽+당한쪽) 조합이 기본키');
            $table->unsignedInteger('blocker_user_idx')->comment('차단한 users.idx');
            $table->unsignedInteger('blocked_user_idx')->comment('차단당한 users.idx');
            $table->datetimes();

            $table->foreign('blocker_user_idx')->references('idx')->on('users')->cascadeOnDelete();
            $table->foreign('blocked_user_idx')->references('idx')->on('users')->cascadeOnDelete();
            $table->primary(['blocker_user_idx', 'blocked_user_idx']);
        });

        // 하루 한 번 돌리는 국세청 사업자 상태조회 결과.
        Schema::create('existence_checks', function (Blueprint $table) {
            $table->comment('국세청 사업자 상태조회 결과(하루 1회)');
            $table->increments('idx')->comment('조회 일련번호');
            $table->unsignedInteger('user_idx')->comment('users.idx');
            $table->dateTime('checked_at')->comment('조회 시각');
            $table->unsignedTinyInteger('result')
                ->comment('0=조회실패 / 1=계속사업자 / 2=폐업 / 3=휴업 / 4=미등록');
            $table->string('raw_status_code', 50)->nullable()->comment('국세청 원본 상태코드');
            $table->string('action_taken')->nullable()->comment('결과에 따라 시스템이 취한 조치');
            $table->datetimes();

            $table->foreign('user_idx')->references('idx')->on('users')->cascadeOnDelete();
            $table->index(['user_idx', 'checked_at']);
        });

        // 발송한 문자 기록. 곳간은 문자만 보내므로 채널 컬럼을 두지 않는다.
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->comment('문자 발송 기록. 문자 외 채널이 없어 채널 컬럼 없음');
            $table->increments('idx')->comment('발송 일련번호');
            $table->unsignedTinyInteger('type')->comment('문자 종류. ReviewDecision 과 같은 번호. 2=자료보완 / 3=승인 / 4=반려');
            $table->unsignedInteger('recipient_user_idx')->nullable()->comment('수신 users.idx');
            $table->boolean('success')->comment('발송 성공 여부. 1=성공 / 0=실패');
            $table->string('provider_message_id')->nullable()->comment('문자사가 돌려준 메시지 ID(FK 아님)');
            $table->text('error_message')->nullable()->comment('발송 실패 사유');
            $table->dateTime('sent_at')->comment('발송 시도 시각');
            $table->datetimes();

            $table->foreign('recipient_user_idx')->references('idx')->on('users');
            $table->index(['recipient_user_idx', 'sent_at']);
        });

        // 운영자 행위 기록. target_type 에 따라 대상 테이블이 달라져 FK 를 걸지 않는다.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->comment('운영자 행위 기록. target_type 에 따라 대상 테이블이 달라져 FK 없음');
            $table->increments('idx')->comment('기록 일련번호');
            $table->unsignedInteger('actor_idx')->nullable()->comment('행위자 users.idx. 시스템 작업이면 NULL');
            $table->string('action', 60)->comment('행위 이름. application.approved / application.license.view 처럼 적는다');
            $table->string('target_type', 40)->comment('대상 종류. user / post 등');
            $table->unsignedInteger('target_idx')->nullable()->comment('대상의 idx. target_type 과 함께 봐야 한다(FK 없음)');
            $table->text('reason')->nullable()->comment('사유');
            $table->dateTime('occurred_at')->comment('발생 시각');
            $table->datetimes();

            $table->foreign('actor_idx')->references('idx')->on('users');
            $table->index(['target_type', 'target_idx']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('existence_checks');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('contact_access_logs');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('inquiries');
    }
};
