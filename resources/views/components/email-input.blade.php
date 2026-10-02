{{--
    이메일 입력: [아이디] @ [도메인] [도메인 선택]. 가입·자료 보완·정보 수정이 같이 쓴다.
      <x-email-input model="email" />
    부모 Livewire 의 $email 하나에 'a@b.c' 로 합쳐 넣는다(둘 다 비우면 빈 값). 검증은 서버의 User::emailRules().
    목록에서 고르면 도메인 칸이 채워지고 잠긴다. '직접 입력'이면 비우고 쓸 수 있게 연다.
    도메인 칸을 type=email 로 두지 않는다 — 브라우저가 한글 도메인을 xn-- 로 바꿔 보내서 알아보기 어렵다.
--}}
@props(['model' => 'email'])

@php
    $domains = ['naver.com', 'gmail.com', 'daum.net', 'hanmail.net', 'kakao.com', 'nate.com', 'hotmail.com', 'outlook.com', 'icloud.com'];
@endphp

<div class="email-row" x-data="{
    email: $wire.entangle('{{ $model }}'),
    local: '',
    domain: '',
    pick: '',
    domains: @js($domains),
    init() {
        const [l = '', d = ''] = (this.email || '').split('@');
        this.local = l;
        this.domain = d;
        this.pick = this.domains.includes(d) ? d : (d ? 'custom' : '');
    },
    // 아이디 칸에 주소 전체를 붙여 넣으면 @ 뒤를 도메인으로 나눈다
    splitLocal() {
        if (! this.local.includes('@')) return this.sync();
        const [l, d = ''] = this.local.split('@');
        this.local = l;
        this.domain = d;
        this.pick = this.domains.includes(d) ? d : (d ? 'custom' : '');
        this.sync();
    },
    choose() {
        this.domain = this.pick === 'custom' ? '' : this.pick;
        if (this.pick === 'custom') this.$nextTick(() => this.$refs.domain.focus());
        this.sync();
    },
    sync() {
        const l = this.local.trim(), d = this.domain.trim();
        this.email = (l || d) ? l + '@' + d : '';
    },
}">
    <input type="text" x-model="local" x-on:input="splitLocal()" inputmode="email" autocomplete="off"
           aria-label="이메일 아이디" placeholder="아이디" maxlength="64">
    <span class="email-at">@</span>
    <input type="text" x-ref="domain" x-model="domain" x-on:input="sync()" x-bind:readonly="pick !== 'custom'"
           aria-label="이메일 도메인" placeholder="도메인" maxlength="190" autocomplete="off">
    <select x-model="pick" x-on:change="choose()" aria-label="이메일 도메인 선택">
        <option value="">선택하세요</option>
        @foreach ($domains as $d)
            <option value="{{ $d }}">{{ $d }}</option>
        @endforeach
        <option value="custom">직접 입력</option>
    </select>
</div>
