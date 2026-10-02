{{--
    목록 이어 불러오기. 부모 Livewire 컴포넌트에 more() 가 있어야 한다.
    PC: '더 보기' 버튼. 휴대폰(850px 이하): 목록 끝에 닿기 200px 전에 저절로 다음 묶음을 불러온다(2026-09-30).
    자동 쪽은 PC 에서 display:none 이라 감지되지 않는다(화면에 없는 요소는 wire:intersect 가 안 걸린다).

    ① 아일랜드 방식(장터): 다음 20건만 조회해서 목록 끝에 덧붙인다. 마지막 묶음이 '{island}-end' 를 보내면 숨는다.
         <x-load-more island="list" :more="$this->chunk['more']" wire:key="..." />
    ② 개수 방식(내 매물): 보여 줄 개수를 늘려 목록을 다시 그린다.
         <x-load-more :shown="$posts->count()" :total="$total" />
--}}
@props(['island' => null, 'more' => false, 'shown' => 0, 'total' => 0])

@if ($island)
    <div {{ $attributes }} x-data="{ done: {{ $more ? 'false' : 'true' }} }" x-on:{{ $island }}-end.window="done = true" x-show="! done">
        <button type="button" class="btn full load-more-btn" style="margin-top:14px" wire:click="more" wire:island.append="{{ $island }}">더 보기</button>
        <div class="load-more-auto" wire:intersect.margin.200px="more" wire:island.append="{{ $island }}">
            <span wire:loading wire:target="more">불러오는 중…</span>
        </div>
    </div>
@elseif ($total > $shown)
    <button type="button" class="btn full load-more-btn" style="margin-top:14px" wire:click="more">
        더 보기 ({{ number_format($shown) }} / {{ number_format($total) }})
    </button>
    <div class="load-more-auto" wire:key="load-more-{{ $shown }}" wire:intersect.margin.200px="more">
        <span wire:loading wire:target="more">불러오는 중…</span>
    </div>
@endif
