{{--
    주소 검색으로 지역 고르기. 부모 Livewire 컴포넌트는 App\Support\PicksRegion 을 쓴다.
      <x-address-search :name="$region_name" hint="..." />
    카카오 우편번호 스크립트는 버튼을 누를 때 처음 불러온다(다른 화면에서는 카카오로 아무것도 안 보낸다).
    새 창 대신 화면을 덮는 레이어(<dialog>)에 끼워 넣는다 — 팝업 차단·앱 안 브라우저 문제를 피하고,
    휴대폰에서는 화면 전체 너비를 쓴다. 도로명·상세주소는 서버로 보내지 않는다.
--}}
@props(['name' => '', 'hint' => ''])

<div class="address-search" x-data="{
    search() {
        this.$refs.dialog.showModal();
        this.$refs.box.innerHTML = '';
        const embed = () => new daum.Postcode({
            oncomplete: (d) => {
                this.$refs.dialog.close();
                this.$wire.pickRegion({ bcode: d.bcode, sido: d.sido, sigungu: d.sigungu, bname: d.bname, bname1: d.bname1 });
            },
            width: '100%',
            height: '100%',
            minWidth: 200,   // 기본 300px 이면 좁은 화면에서 오른쪽이 잘린다
        }).embed(this.$refs.box);
        if (window.daum && window.daum.Postcode) return embed();
        const s = document.createElement('script');
        s.src = 'https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
        s.onload = embed;
        // 회사망 차단·카카오 장애. 빈 화면 대신 안내하고, 다음에 누르면 다시 불러온다
        s.onerror = () => {
            s.remove();
            this.$refs.box.innerHTML = '<p class=&quot;note warning&quot; style=&quot;margin:16px&quot;>주소 검색을 불러오지 못했습니다. 인터넷 연결을 확인하고 잠시 후 다시 시도해 주세요.</p>';
        };
        document.head.appendChild(s);
    },
}">
    <div class="address-picked">
        <span>{{ $name ?: '아직 고르지 않았습니다' }}</span>
        <button type="button" class="btn" x-on:click="search()">{{ $name ? '다시 검색' : '주소 검색' }}</button>
    </div>
    @if ($hint) <small>{{ $hint }}</small> @endif

    <dialog class="address-dialog" x-ref="dialog" wire:ignore>
        <div class="dialog-header">
            <h2>주소 검색</h2>
            <button type="button" class="btn small" x-on:click="$refs.dialog.close()">닫기</button>
        </div>
        <div class="address-box" x-ref="box"></div>
    </dialog>
</div>
