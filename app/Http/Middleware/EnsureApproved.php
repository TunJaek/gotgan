<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * 문의·등록·연락처 확인처럼 "행동"하는 요청에만 건다.
 * 열람 라우트에는 걸지 않는다.
 */
class EnsureApproved
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isApproved()) {
            return redirect()->route('account.index')->with('notice', '사업자 확인을 마친 회원만 이용할 수 있습니다.');
        }

        return $next($request);
    }
}
