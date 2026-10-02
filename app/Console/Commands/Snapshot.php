<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * 개발용. 로그인 상태로 화면 HTML 을 뽑아 저장한다. 브라우저 자동화 없이 화면을 확인할 때 쓴다.
 */
class Snapshot extends Command
{
    protected $signature = 'gotgan:snapshot {path} {--as=} {--out=}';

    protected $description = '지정한 경로를 로그인 상태로 렌더링해 HTML 파일로 저장한다 (로컬 전용)';

    public function handle(): int
    {
        if (! app()->isLocal()) {
            $this->error('로컬 환경에서만 쓸 수 있습니다.');

            return self::FAILURE;
        }

        if ($bizHash = $this->option('as')) {
            $user = User::where('biz_no', \App\Support\BizNumber::normalize($bizHash))->first();

            if (! $user) {
                $this->error('그 사업자번호의 계정이 없습니다: '.$bizHash);

                return self::FAILURE;
            }

            Auth::login($user);
        }

        // asset() 이 APP_URL 을 쓰도록 절대 주소로 만든다
        $request = \Illuminate\Http\Request::create(
            rtrim(config('app.url'), '/').$this->argument('path'), 'GET'
        );
        $request->setLaravelSession(app('session.store'));
        $response = app()->handle($request);

        $out = $this->option('out') ?: storage_path('app/snapshot.html');
        file_put_contents($out, $response->getContent());

        $this->info($response->getStatusCode().' → '.$out.' ('.strlen($response->getContent()).' bytes)');

        return self::SUCCESS;
    }
}
