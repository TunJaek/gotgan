<?php

use App\Models\Agreement;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\User;
use App\Support\Pass;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use League\CommonMark\Extension\Table\TableExtension;

// 이용약관·개인정보처리방침. 로그인하지 않아도 볼 수 있어야 한다.
foreach (Agreement::DOCUMENTS as $document => $title) {
    Route::get('/'.$document, fn () => view('legal.show', [
        'title' => $title,
        // 표가 많아 표 확장을 켠다. 본문에 HTML 을 섞지 않으므로 HTML 은 걸러 낸다.
        'html' => Str::markdown(Agreement::currentText($document), ['html_input' => 'strip'], [
            new TableExtension,
        ]),
    ]))->name($document);
}

// 휴대폰 본인인증(다날 PASS). 흐름은 app/Support/Pass.php 맨 위 설명을 본다.
Route::post('/pass/start/{purpose}', function (Request $request, string $purpose) {
    if (in_array($purpose, ['edit', 'phone'], true) && ! auth()->check()) {
        return redirect()->route('login');
    }

    // 정보 수정 입장은 가입한 번호로만 인증한다(입력값을 받지 않는다)
    $phone = $purpose === 'edit' ? auth()->user()->phone : (string) $request->input('phone');
    $start = Pass::start($purpose, $phone);

    if (! $start['ok']) {
        return redirect(Pass::returnUrl($purpose))->with('notice', $start['error']);
    }

    return view('pass.redirect', $start + ['fake' => config('gotgan.pass.driver') === 'log']);
})->whereIn('purpose', Pass::PURPOSES)->middleware('throttle:10,10')->name('pass.start');

// 다날이 다른 사이트에서 부른다. 세션 쿠키가 SameSite=lax 라 여기엔 쿠키가 오지 않는다.
// 세션을 켜면 쿠키 없는 요청에 새 세션을 만들어 이용자의 로그인 쿠키를 덮어쓰므로 끈다.
// TID 만 받아 곳간 안의 GET 주소로 넘기고, 거기서 세션의 주문번호로 확인한다.
$no_session = [StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class];
Route::match(['get', 'post'], '/pass/callback', fn (Request $request) => redirect()->route('pass.complete', ['tid' => (string) $request->input('TID')]))
    ->withoutMiddleware($no_session)->name('pass.callback');
Route::match(['get', 'post'], '/pass/cancel', fn () => redirect()->route('pass.cancelled'))
    ->withoutMiddleware($no_session)->name('pass.cancel');

Route::get('/pass/complete', function (Request $request) {
    $tid = (string) $request->query('tid');

    if (! preg_match('/^[A-Za-z0-9]{1,64}$/', $tid)) {
        return redirect()->route('market.index')->with('notice', '잘못된 본인인증 결과입니다. 처음부터 다시 진행해 주세요.');
    }

    $done = Pass::confirm($tid);
    $back = redirect(Pass::returnUrl($done['purpose'] ?? ''));

    return $done['ok'] ? $back : $back->with('notice', $done['error']);
})->middleware('throttle:10,10')->name('pass.complete');

Route::get('/pass/cancelled', function () {
    $purpose = session('pass.request.purpose', '');
    session()->forget('pass.request');

    return redirect(Pass::returnUrl($purpose))->with('notice', '본인인증을 취소했습니다.');
})->name('pass.cancelled');

// 개발용 가짜 인증(PASS_DRIVER=log). 운영 서버에서는 열리지 않는다.
Route::match(['get', 'post'], '/pass/fake', function (Request $request) {
    abort_if(config('gotgan.pass.driver') !== 'log' || app()->isProduction(), 404);

    $pending = session('pass.request');

    if (! $pending) {
        return redirect()->route('market.index');
    }
    if (! $request->filled('name') || ! $request->filled('ci')) {
        return view('pass.fake', ['phone' => $pending['phone']]);
    }

    $request->validate(['name' => ['required', 'string', 'max:100'], 'ci' => ['required', 'string', 'max:255']]);
    session()->forget('pass.request');
    Pass::remember($pending['purpose'], $pending['phone'], (string) $request->input('name'), (string) $request->input('ci'));

    return redirect(Pass::returnUrl($pending['purpose']));
})->name('pass.fake');

// 열람은 누구나. 문의·등록·연락처 확인만 승인 회원으로 제한한다.
Route::livewire('/', 'posts.index')->name('market.index');

// 매물 상세. {post} 이름이 아래 ⚡detail.blade.php 의 public Post $post 와 짝이 된다.
// 주소의 숫자로 posts 를 찾아 넣어주는 일(라우트 모델 바인딩)은 라라벨이 한다.
// whereNumber 를 빼면 /posts/create 까지 이 줄이 삼켜서 등록 화면이 404 가 된다.
Route::livewire('/posts/{post}', 'posts.detail')->whereNumber('post')->name('posts.detail');

// 업체 페이지. 이 업체가 올린 팝니다·구해요 글(누구나). {user} 는 users.idx
Route::livewire('/companies/{user}', 'companies.show')->whereNumber('user')->name('companies.show');

Route::get('/images/{post}/{n}', function (Post $post, int $n) {
    $photo = $post->photos[$n] ?? abort(404);
    abort_unless(Storage::disk('local')->exists($photo['key']), 404);

    // 주소에 ?v= 가 붙어 있어서 사진이 바뀌면 주소도 바뀐다. 그래서 길게 캐시해도 안전하다.
    // private 인 이유 — 매물 사진은 로그인 여부를 타는 경로라 공용 캐시에 담기면 안 된다.
    return Storage::disk('local')->response($photo['key'], null, [
        'Cache-Control' => 'private, max-age=31536000, immutable',
    ]);
})->whereNumber(['post', 'n'])->name('images.show');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'auth.login')->name('login');
    Route::livewire('/register', 'auth.register')->name('register');
    Route::livewire('/password/find', 'auth.reset')->name('password.find');
    Route::view('/register/duplicate', 'auth.duplicate')->name('register.duplicate');
});

Route::view('/register/complete', 'auth.complete')->name('register.complete');

Route::post('/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('market.index');
})->name('logout');

Route::middleware('auth')->group(function () {
    Route::livewire('/account', 'account.status')->name('account.index');
    Route::livewire('/account/supplement', 'account.supplement')->name('account.supplement');
    Route::livewire('/account/withdraw', 'account.withdraw')->name('account.withdraw');
    Route::livewire('/account/edit', 'account.edit')->name('account.edit');
    Route::livewire('/account/posts', 'account.posts')->name('account.posts');   // 내 매물 · 관심 매물(?tab=favorites)
});

// 관리자
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // 헤더에 링크를 걸지 않는다. 주소를 직접 쳐서 들어온다.
    Route::livewire('/', 'admin.index')->name('index');

    Route::livewire('/applications', 'admin.applications')->name('applications');
    Route::livewire('/applications/{user}', 'admin.review')->whereNumber('user')->name('review');

    // 신고. 목록은 대상별로 묶여 있고, 상세는 (대상 종류, 대상 번호)로 연다.
    Route::livewire('/reports', 'admin.reports')->name('reports');
    Route::livewire('/reports/{type}/{idx}', 'admin.report')
        ->whereNumber('type')->whereNumber('idx')->name('report');

    // 숨김·정지를 되돌리는 자리. 신고를 거치지 않고도 올 수 있어야 한다.
    Route::livewire('/posts', 'admin.posts')->name('posts');
    Route::livewire('/posts/{post}', 'admin.post')->whereNumber('post')->name('post');
    Route::livewire('/users', 'admin.users')->name('users');
    Route::livewire('/users/{user}', 'admin.user')->whereNumber('user')->name('user');

    // 운영 기록. 자동 점검과 문자 발송이 남긴 결과를 관리자가 확인한다.
    Route::livewire('/existence-checks', 'admin.existence-checks')->name('existence-checks');
    Route::livewire('/notification-logs', 'admin.notification-logs')->name('notification-logs');

    // 사업자등록증은 공개 경로에 두지 않고 권한 확인 후 내보낸다.
    // 경로는 계정 번호로 정해져 있고 확장자가 없어서, 종류는 파일에서 알아낸다.
    Route::get('/applications/{user}/license', function (User $user) {
        abort_unless(Storage::exists($user->licenseKey()), 404);
        AuditLog::record('application.license.view', 'user', $user->idx);

        return Storage::response($user->licenseKey(), null, [
            'Content-Type' => Storage::mimeType($user->licenseKey()),
        ]);
    })->whereNumber('user')->name('license');
});

// D·E 단계에서 구현
Route::livewire('/posts/create', 'posts.create')->name('posts.create')->middleware(['auth', 'approved']);

// 수정은 등록 화면을 그대로 쓴다. {post} 가 mount(?Post $post) 로 들어가고,
// 내 글인지는 그 안에서 확인한다. whereNumber 가 없으면 /posts/create/edit 같은 것도 걸린다.
Route::livewire('/posts/{post}/edit', 'posts.create')->whereNumber('post')
    ->middleware(['auth', 'approved'])->name('posts.edit');
// 문의함·대화. {room} 이 대화 컴포넌트의 public Inquiry $room 과 짝이 된다.
Route::middleware(['auth', 'approved'])->group(function () {
    Route::livewire('/inquiries', 'inquiries.index')->name('inquiries.index');
    Route::livewire('/inquiries/{room}', 'inquiries.chat')->whereNumber('room')->name('inquiries.show');
});
