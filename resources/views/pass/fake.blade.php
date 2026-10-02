{{-- 개발용 가짜 본인인증(PASS_DRIVER=log). 운영 서버에서는 열리지 않는다. --}}
<x-layout title="본인인증 (개발용)">
    <div class="narrow">
        <div class="page-head">
            <div>
                <h1>본인인증 (개발용)</h1>
                <p class="sub">PASS_DRIVER=log 입니다. 실제 다날 인증 대신 아래 값으로 인증된 것으로 처리합니다.</p>
            </div>
        </div>

        <form class="form-shell" method="post" action="{{ route('pass.fake') }}">
            @csrf
            <div class="form-inner">
                <div class="field">
                    <label>인증할 휴대폰 번호</label>
                    <p><strong>{{ preg_replace('/^(\d{3})(\d{3,4})(\d{4})$/', '$1-$2-$3', $phone) }}</strong></p>
                    <small>인증을 시작할 때 입력한 번호입니다.</small>
                </div>
                <div class="field">
                    <label for="name">이름</label>
                    <input id="name" name="name" value="{{ old('name', '홍길동') }}" required>
                </div>
                <div class="field">
                    <label for="ci">CI (같은 사람이면 같은 값)</label>
                    <input id="ci" name="ci" value="{{ old('ci', 'FAKE-CI-홍길동') }}" required>
                    <small>실제 CI 는 사람마다 하나로 정해져 있습니다. 비밀번호 찾기·휴대폰 변경을 시험할 때는 가입 때와 같은 값을 넣으세요.</small>
                </div>
            </div>
            <div class="form-footer">
                <a class="btn" href="{{ route('pass.cancel') }}">취소</a>
                <button type="submit" class="btn primary">인증 완료</button>
            </div>
        </form>
    </div>
</x-layout>
