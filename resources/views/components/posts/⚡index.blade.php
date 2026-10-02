<?php

/*
 * ┌─ 장터 목록 ─────────────────────────────────────────────────────
 * │ 오는 길    GET /  ·  누구나 (미들웨어 없음)
 * │            routes/web.php  Route::livewire('/', 'posts.index')
 * │
 * │ 내놀 것    $posts — 판매중(status=1) · 미노출아님 · 선택한 타입 · 게시기간 안
 * │            검색어·카테고리·지역으로 거르고, 20건씩 '더 보기'로 늘린다
 * │
 * │ 다시 오는 길
 * │            검색어 입력 / 칩·카테고리·지역 변경 → POST /livewire/update
 * │            → 이 파일이 다시 실행되고 바뀐 <div> 조각만 교체된다
 * │            조건은 주소(?q=&type=&cat=&sido=&sigungu=)에도 남아서 뒤로 가기·링크 공유가 된다
 * │
 * │ 나가는 길  카드 클릭 → /posts/{idx} 상세
 * │
 * │ 전체 흐름은 docs/가이드.md 10장
 * └────────────────────────────────────────────────────────────────
 */

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\Category;
use App\Models\Post;
use App\Support\Sido;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /** 한 번에 더 불러오는 개수 */
    private const PAGE = 20;

    // 화면이 기억하는 값. 사용자가 바꾸면 자동으로 여기 담기고 화면이 다시 그려진다. #[Url] 은 주소에도 남긴다.
    #[Url(as: 'q', except: '')]
    public string $keyword = '';          // 검색어

    #[Url(except: 1)]
    public int $type = 1;                 // PostType::Sell

    #[Url(as: 'cat', except: 0)]
    public int $category = 0;             // 0=전체. 고르면 하위 분류까지

    #[Url(except: '')]
    public string $sido = '';             // 법정동 코드 앞 2자리

    #[Url(except: '')]
    public string $sigungu = '';          // 법정동 코드 앞 5자리

    /**
     * 지금까지 불러온 묶음 번호(20건씩). '더 보기'·자동 불러오기는 다음 묶음 20건만 조회해서
     * 목록 끝에 덧붙인다(@island + wire:island.append, 2026-09-30). 앞의 글은 다시 조회하지도 그리지도 않는다.
     */
    public int $page = 1;

    /** 조건이 바뀌면 처음 20건부터 다시 */
    public function updated($property): void
    {
        if ($property === 'sido') {
            $this->sigungu = '';
        }
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    /** 휴대폰 '필터 초기화'(카테고리·지역). 검색어와 팝니다/구해요 종류는 그대로 */
    public function resetFilters(): void
    {
        $this->reset(['category', 'sido', 'sigungu']);
        $this->page = 1;
    }

    public function more(): void
    {
        $this->page++;
        unset($this->chunk);   // 새 묶음으로 다시 계산
    }

    /**
     * 지금 묶음의 글 20건과 그 뒤에 더 있는지. 21건을 가져와 21번째가 있으면 '더 있음'이다(전체를 세지 않는다).
     *
     * @return array{posts: \Illuminate\Support\Collection, more: bool}
     */
    #[Computed]
    public function chunk(): array
    {
        $rows = $this->listQuery()
            ->with('user')   // 카드에 업체명을 쓰므로 미리 같이 불러온다 (N+1 방지)
            ->skip(($this->page - 1) * self::PAGE)
            ->take(self::PAGE + 1)
            ->get();

        return ['posts' => $rows->take(self::PAGE), 'more' => $rows->count() > self::PAGE];
    }

    /** 지금 보이는 글의 공통 조건. 지역 선택지도 여기서 뽑는다. */
    private function listedQuery()
    {
        return Post::query()
            ->where('status', PostStatus::Selling)
            ->where('is_hidden', 0)
            ->where('post_type', $this->type)
            ->stillListed();
    }

    /** 검색어·카테고리·지역·정렬까지 붙인 목록 조회. 지역 값은 with() 가 선택지와 맞춰 둔 것을 쓴다. */
    private function listQuery()
    {
        $query = $this->listedQuery();

        // 검색어는 띄어쓰기로 나눠, 단어마다 제목이나 설명 중 한 곳에 있으면 된다(규격·모델명은 설명에 적히므로).
        // 값은 바인딩으로 넘겨야 SQL 인젝션이 막힌다. %·_ 는 글자 그대로 찾게 이스케이프한다.
        // ponytail: 전체 스캔 LIKE. 글이 수만 건이 되어 느려지면 FULLTEXT(ngram) 로 바꾼다
        foreach (preg_split('/\s+/', trim($this->keyword), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like));
        }
        if ($this->category) {
            $query->whereIn('category_id', Category::withDescendants($this->category));
        }
        // 코드 앞자리가 곧 상위 지역이다
        $region = $this->sigungu ?: $this->sido;
        if (preg_match('/^\d{2}(\d{3})?$/', $region)) {
            $query->where('region_code', 'like', $region.'%');
        }

        // 최신순만. 낮은 가격순은 개당·일괄 가격이 섞여 엉뚱한 비교가 되므로 가격 기준 칸이 생길 때까지 뺐다(2026-09-30)
        return $query->orderByDesc('created_at');
    }

    public function with(): array
    {
        $is_want = $this->type === PostType::Want->value;

        // 선택지에 없는 지역(예전에 공유된 링크 등)은 버린다. 선택칸에 보이는 것과 실제 조건을 맞추기 위해서다.
        // 선택지는 캐시한다(Post::REGIONS_CACHE). 검색어를 칠 때마다 전체를 다시 묶지 않게.
        $regions = Cache::remember(Post::REGIONS_CACHE.$this->type, 600, fn () => $this->regions($this->listedQuery()));
        if (! isset($regions[$this->sido])) {
            $this->sido = '';
        }
        if (! isset($regions[$this->sido]['sigungus'][$this->sigungu])) {
            $this->sigungu = '';
        }
        return [
            'is_want' => $is_want,
            'total' => $this->listQuery()->count(),   // 건수 표시용. 이어 불러올 때는 돌지 않는다
            'categories' => Category::menu(),
            'regions' => $regions,
            // 상단 띠의 종류별 건수. all = 지금 올라와 있는 전체, today = 그중 오늘 0시(한국 시간)부터 올라온 것.
            // 조건과 무관한 수라 60초 캐시(조금 늦게 바뀌어도 된다). 키: [post_type => 건수]
            'counts' => Cache::remember('market.counts', 60, function () {
                $by_type = fn ($query) => $query->toBase()->selectRaw('post_type, COUNT(*) AS n')->groupBy('post_type')->pluck('n', 'post_type')->all();
                $listed = fn () => Post::query()->where('status', PostStatus::Selling)->where('is_hidden', 0)->stillListed();

                return ['all' => $by_type($listed()), 'today' => $by_type($listed()->where('created_at', '>=', today()))];
            }),
        ];
    }

    /**
     * 지역 선택지. 실제로 올라와 있는 글의 지역만 보여 준다(지역 목록 표를 두지 않는다).
     * region_name '경기 성남시 분당구 정자동' → 시도 '경기'(코드로 정함), 시군구 '성남시 분당구'
     *
     * 이름은 브라우저가 보낸 값이 저장된 것이라, 글 하나가 선택지 이름을 바꾸지 못하게 한다.
     *   시도: 코드로 정한다(App\Support\Sido). 모르는 코드면 저장된 첫 단어
     *   시군구: 같은 코드에서 가장 많이 쓰인 이름
     *
     * @return array<string, array{name: string, sigungus: array<string, string>}>
     */
    private function regions($query): array
    {
        $rows = $query->whereNotNull('region_code')
            ->selectRaw('LEFT(region_code, 5) AS code, region_name AS name, COUNT(*) AS n')
            ->groupBy('code', 'region_name')
            ->orderBy('code')
            ->get();

        // 동을 뗀 '서울 동작구' 단위로 표를 모은다. 동이 달라도 같은 시군구 이름이면 한 표로 친다.
        $votes = [];
        foreach ($rows as $row) {
            $area = Str::beforeLast((string) $row->name, ' ');
            $votes[$row->code][$area] = ($votes[$row->code][$area] ?? 0) + $row->n;
        }

        $regions = [];
        foreach ($votes as $code => $areas) {
            arsort($areas);                       // 가장 많이 쓰인 이름이 앞으로
            $area = (string) array_key_first($areas);
            $sido = substr($code, 0, 2);
            $regions[$sido]['name'] ??= Sido::name($sido) ?? Str::before($area, ' ');
            // 첫 단어(시도)를 뗀 나머지. 세종처럼 시군구가 없으면 시도 이름
            $regions[$sido]['sigungus'][$code] = str_contains($area, ' ') ? Str::after($area, ' ') : $regions[$sido]['name'];
        }

        return $regions;
    }
}; ?>

<div>
    {{-- 상단 띠: 화면 전체 폭(CSS 로 .wrap 바깥까지 늘린다). 검색창도 이 안에 있다 --}}
    <section class="market-hero">
      <div class="market-hero-main">
        <p class="market-hero-eyebrow">{{ $is_want ? '구해요' : '장터' }} · 곳간</p>
        <h1>{{ $is_want ? '필요한 재고, 가진 사업자에게' : '남는 재고, 필요한 사업자에게' }}</h1>
        <p class="market-hero-sub">사업자 간 남는 재고·자재 직거래</p>

        {{-- wire:model.live = 입력하는 즉시 위 PHP 의 $keyword 가 바뀌고 목록이 다시 그려진다 --}}
        <div class="searchbar">
            <span class="search-icon">{!! App\Support\Icon::render('search') !!}</span>
            <input type="search" placeholder="찾는 물건을 입력하세요" aria-label="검색어" wire:model.live.debounce.400ms="keyword">
        </div>

        <div class="market-hero-stats">
            <span><b>{{ number_format($counts['all'][PostType::Sell->value] ?? 0) }}</b>건 판매중</span>
            <span><b>{{ number_format($counts['all'][PostType::Want->value] ?? 0) }}</b>건 구해요</span>
            {{-- 회원 상태 한 줄. 승인 회원·관리자에게는 보이지 않는다 --}}
            @guest
                <span>문의·등록은 사업자 승인 후 · <a href="{{ route('register') }}">사업자 회원 가입</a></span>
            @else
                @if (auth()->user()->review_status === App\Enums\ApplicationStatus::Reviewing)
                    <span>사업자 확인 중 · 승인 후 문의·등록 가능</span>
                @endif
            @endguest
        </div>
      </div>

        {{-- 오른쪽 박스: 오늘 0시부터 올라온 글 수(휴대폰에서는 띠 맨 아래 한 줄).
             둘 다 0건이면 비어 보이므로 박스를 빼고 띠를 한 칸으로 쓴다(CSS :has) --}}
        @if (array_sum($counts['today'] ?? []) > 0)
            <aside class="market-today" aria-label="오늘 등록">
                <p class="market-today-title">오늘 등록 <small>{{ now()->isoFormat('M월 D일') }}</small></p>
                <div class="market-today-rows">
                    <div><span>팝니다</span><b>{{ number_format($counts['today'][PostType::Sell->value] ?? 0) }}</b>건</div>
                    <div><span>구해요</span><b>{{ number_format($counts['today'][PostType::Want->value] ?? 0) }}</b>건</div>
                </div>
            </aside>
        @endif
    </section>

    <div class="market-layout">
        {{-- PC: 왼쪽 카테고리·지역 --}}
        <aside class="sidebar">
            <h2>카테고리</h2>
            @php
                // 대분류만 보이고, 고른 카테고리가 속한 대분류 하나만 펼친다(2026-10-02). $categories 는 나무 순서라 앞에서부터 대분류를 기억한다.
                $top_of = [];
                $top = 0;
                foreach ($categories as $idx => $c) {
                    $top = $c['depth'] === 1 ? $idx : $top;
                    $top_of[$idx] = $top;
                }
                $open_top = $top_of[$category] ?? 0;
                $has_children = array_count_values(array_filter($top_of, fn ($t, $idx) => $t !== $idx, ARRAY_FILTER_USE_BOTH));
            @endphp
            <button type="button" @class(['category-button', 'active' => ! $category]) wire:click="$set('category', 0)">전체</button>
            @foreach ($categories as $idx => $c)
                @continue($c['depth'] > 1 && $top_of[$idx] !== $open_top)
                <button type="button" @class(['category-button', 'active' => $category === $idx])
                        style="padding-left:{{ 10 + ($c['depth'] - 1) * 14 }}px" wire:click="$set('category', {{ $idx }})"
                        @if (isset($has_children[$idx])) aria-expanded="{{ $open_top === $idx ? 'true' : 'false' }}" @endif>
                    {{ $c['name'] }}
                    @isset($has_children[$idx])<span class="cat-arrow" aria-hidden="true">{{ $open_top === $idx ? '▾' : '▸' }}</span>@endisset
                </button>
            @endforeach

            <hr>
            <h2>지역</h2>
            <label for="side-sido">시·도</label>
            <select id="side-sido" wire:model.live="sido">
                <option value="">전체</option>
                @foreach ($regions as $code => $r)
                    <option value="{{ $code }}">{{ $r['name'] }}</option>
                @endforeach
            </select>
            @if ($sido && isset($regions[$sido]))
                <label for="side-sigungu">시·군·구</label>
                <select id="side-sigungu" wire:model.live="sigungu">
                    <option value="">전체</option>
                    @foreach ($regions[$sido]['sigungus'] as $code => $name)
                        <option value="{{ $code }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif
        </aside>

        <div>
            {{-- 휴대폰: 사이드바 대신 접이식 필터. 접혀 있어도 고른 조건을 요약해 보여 준다.
                 wire:ignore.self = 다시 그려져도 펼침 상태(open)는 그대로 둔다 --}}
            @php
                $filter_labels = array_filter([
                    $categories[$category]['name'] ?? null,
                    $sido ? trim(($regions[$sido]['name'] ?? '').' '.($regions[$sido]['sigungus'][$sigungu] ?? '')) : null,
                ]);
            @endphp
            <details class="mobile-cats mobile-filters" wire:ignore.self>
                <summary>
                    {{ $filter_labels ? '필터 '.count($filter_labels).' · '.implode(' · ', $filter_labels) : '필터' }}
                </summary>
                <div class="mobile-filters-body">
                    <select wire:model.live="category" aria-label="카테고리">
                        <option value="0">카테고리 전체</option>
                        @foreach ($categories as $idx => $c)
                            <option value="{{ $idx }}">{{ str_repeat('　', $c['depth'] - 1) }}{{ $c['name'] }}</option>
                        @endforeach
                    </select>
                    <div class="input-row">
                        <select wire:model.live="sido" aria-label="시·도">
                            <option value="">지역 전체</option>
                            @foreach ($regions as $code => $r)
                                <option value="{{ $code }}">{{ $r['name'] }}</option>
                            @endforeach
                        </select>
                        @if ($sido)
                            <select wire:model.live="sigungu" aria-label="시·군·구">
                                <option value="">시·군·구 전체</option>
                                @foreach ($regions[$sido]['sigungus'] ?? [] as $code => $name)
                                    <option value="{{ $code }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                    @if ($filter_labels)
                        <button type="button" class="text-button" wire:click="resetFilters">필터 초기화</button>
                    @endif
                </div>
            </details>

            <div class="toolbar">
                <div class="chips">
                    {{-- 종류를 바꾸면 주소를 옮긴다. 제목·메뉴 강조(장터/구해요)가 같이 바뀌어야 해서다. 검색 조건은 들고 간다. --}}
                    @foreach (PostType::cases() as $case)
                        <a @class(['chip', 'active' => $type === $case->value])
                           href="{{ route('market.index', array_filter([
                               'type' => $case === PostType::Sell ? null : $case->value,
                               'q' => $keyword ?: null, 'cat' => $category ?: null,
                               'sido' => $sido ?: null, 'sigungu' => $sigungu ?: null,
                           ])) }}">{{ $case->label() }}</a>
                    @endforeach
                </div>

                <div class="toolbar-right">
                    <span class="dim">{{ number_format($total) }}건 · 최신순</span>
                </div>
            </div>

            @if ($total === 0)
                <div class="empty">
                    {!! App\Support\Icon::render('box') !!}
                    <p>조건에 맞는 매물이 없습니다.</p>
                    <div class="actions">
                        @auth
                            <a class="btn primary" href="{{ route('posts.create') }}{{ $is_want ? '?type=want' : '' }}">
                                {{ $is_want ? '구해요에 올리기' : '내 재고 올리기' }}
                            </a>
                        @else
                            <a class="btn primary" href="{{ route('login') }}">내 재고 올리기</a>
                        @endauth
                    </div>
                </div>
            @else
                {{-- 목록만 아일랜드. 이어 불러오면 이 안에 새 묶음 20건만 덧붙는다. 조건이 바뀌면(page=1) 통째로 다시 그린다 --}}
                <div class="listing-set">
                    @island(name: 'list', always: true)
                    @php $is_want = $this->type === App\Enums\PostType::Want->value; @endphp
                    @foreach ($this->chunk['posts'] as $post)
                        <x-post-card :post="$post" :want="$is_want" wire:key="post-{{ $post->idx }}" />
                    @endforeach
                    {{-- 마지막 묶음이면 '더 보기'·자동 불러오기를 숨기라고 알린다 --}}
                    @unless ($this->chunk['more'])
                        <div hidden x-data x-init="$dispatch('list-end')"></div>
                    @endunless
                    @endisland
                </div>

                <x-load-more island="list" :more="$this->chunk['more']"
                             wire:key="load-more-{{ md5(json_encode([$type, $keyword, $category, $sido, $sigungu, $total])) }}" />
            @endif
        </div>
    </div>
</div>
