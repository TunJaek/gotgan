@props(['sort' => null, 'current' => [null, null]])

{{--
 정렬되는 표 머리글 한 칸.

   <x-admin.th sort="created_at" :current="$this->currentSort()">신고일</x-admin.th>

 sort 를 안 주면 그냥 머리글이다. 누르면 컴포넌트의 sortBy() 가 돌고,
 허용하지 않은 열이면 거기서 무시된다(App\Concerns\AdminList).

 정렬(가운데)은 CSS 가 표 전체에 걸어두므로 여기서 열마다 정하지 않는다.
--}}

@php
    [$col, $dir] = $current;
    $on = $sort !== null && $col === $sort;
@endphp

@if ($sort === null)
    <th>{{ $slot }}</th>
@else
    <th @class(['sortable', 'on' => $on])>
        <button type="button" wire:click="sortBy('{{ $sort }}')">
            {{ $slot }}
            <span class="arw">{{ $on ? ($dir === 'asc' ? '▲' : '▼') : '⇅' }}</span>
        </button>
    </th>
@endif
