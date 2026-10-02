<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 사업체 정보는 users 에 같이 둔다. 계정과 1:1 이라 테이블을 나눌 이유가 없었다.
        // users.name 은 대표자명, company_name 이 상호다.
        Schema::table('users', function (Blueprint $table) {
            $table->string('company_name')->after('name')->comment('상호');
            $table->char('biz_no', 10)->unique()->after('company_name')
                ->comment('사업자등록번호 10자리. 하이픈 없이 저장하고 로그인 아이디로 쓴다');
            $table->string('industry')->nullable()->after('biz_no')->comment('업종');
            $table->string('region_code', 10)->after('industry')->comment('지역코드(시도+시군구)');
            $table->unsignedTinyInteger('review_status')->default(1)->after('region_code')
                ->comment('가입 심사. 0=작성중 / 1=심사중 / 2=보완요청 / 3=승인 / 4=반려');
            $table->dateTime('submitted_at')->nullable()->after('review_status')->comment('가입 신청 제출 시각');
            $table->dateTime('approved_at')->nullable()->after('submitted_at')->comment('승인 시각');
            $table->unsignedTinyInteger('existence_status')->default(0)->after('approved_at')
                ->comment('일일 국세청 조회 결과. 0=미조회 / 1=계속사업자 / 2=폐업·휴업(게시물 전체 미노출)');
            $table->dateTime('existence_checked_at')->nullable()->after('existence_status')
                ->comment('마지막 국세청 조회 시각');

            // 가입 심사. 계정당 1건이라 따로 테이블을 두지 않는다.
            // 보완 후 재제출도 같은 행을 다시 쓴다.
            $table->unsignedInteger('submission_revision')->default(1)->after('existence_checked_at')
                ->comment('제출 회차. 보완 요청마다 1씩 증가');
            $table->unsignedInteger('lock_version')->default(0)->after('submission_revision')
                ->comment('낙관적 잠금 버전. 두 관리자가 동시에 심사하는 것을 막는다');

            $table->index(['review_status', 'submitted_at']);
            $table->index(['review_status', 'existence_status']);
        });

        // 휴대폰 인증번호. 가입 절차에서만 쓰고 사용 후 삭제한다.
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

        // 심사 이력. 덧붙이기만 하고 수정·삭제하지 않는다.
        Schema::create('application_reviews', function (Blueprint $table) {
            $table->comment('심사 이력. 덧붙이기 전용(수정·삭제 금지)');
            $table->increments('idx')->comment('이력 일련번호');
            $table->unsignedInteger('user_idx')->comment('심사 대상 users.idx');
            $table->unsignedInteger('reviewer_idx')->comment('심사한 운영자 users.idx');
            $table->unsignedTinyInteger('decision')
                ->comment('users.review_status 와 같은 번호. 2=자료보완 / 3=승인 / 4=반려');
            $table->text('reason')->nullable()->comment('심사 사유. 보완·반려는 애플리케이션에서 필수 검증');
            $table->dateTime('reviewed_at')->comment('심사 시각');
            $table->datetimes();

            $table->foreign('user_idx')->references('idx')->on('users')->cascadeOnDelete();
            $table->foreign('reviewer_idx')->references('idx')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_reviews');
        Schema::dropIfExists('phone_verifications');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'company_name', 'biz_no', 'industry', 'region_code',
                'review_status', 'submitted_at', 'approved_at',
                'existence_status', 'existence_checked_at',
                'submission_revision', 'lock_version',
            ]);
        });
    }
};
