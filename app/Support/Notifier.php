<?php

namespace App\Support;

use App\Enums\ReviewDecision;
use App\Models\User;
use App\Models\NotificationLog;

/**
 * 심사 결과 문자. 프린팅박스와 같은 방식으로 즉시 보내고 결과를 그대로 돌려준다.
 * 발송 실패가 승인 처리를 되돌리지는 않는다. 관리자 화면에서 다시 시도한다.
 */
class Notifier
{
    /** @return array{success: bool, message: string} */
    public static function reviewResult(User $user, ReviewDecision $decision, ?string $reason = null): array
    {
        $body = match ($decision) {
            ReviewDecision::Approved => "[곳간] {$user->company_name} 가입이 승인되었습니다. 이제 재고를 등록하고 문의할 수 있습니다.",
            ReviewDecision::Supplement => "[곳간] {$user->company_name} 가입 심사에 보완이 필요합니다. 사유: {$reason}",
            ReviewDecision::Rejected => "[곳간] {$user->company_name} 가입이 반려되었습니다. 사유: {$reason}",
        };

        $result = Sms::send($user->phone, $body);

        NotificationLog::create([
            'type' => $decision,
            'recipient_user_idx' => $user->idx,
            'success' => $result['success'],
            'provider_message_id' => $result['message_id'],
            'error_message' => $result['error'],
            'sent_at' => now(),
        ]);

        return [
            'success' => $result['success'],
            'message' => $result['success']
                ? '결과 문자를 보냈습니다.'
                : '문자 발송에 실패했습니다: '.($result['error'] ?? '알 수 없는 오류'),
        ];
    }
}
