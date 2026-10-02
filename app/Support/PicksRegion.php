<?php

namespace App\Support;

/**
 * 지역을 주소 검색(카카오 우편번호, <x-address-search>)으로 고른다. 가입·자료 보완·정보 수정·매물 등록이 같이 쓴다.
 * 도로명·상세주소는 받지 않고 법정동 코드 앞자리와 이름만 남긴다(개인정보 최소화, 2026-09-29).
 *
 *   회원 사업장·매물 소재지 모두 읍면동 8자리 '11590102' / '서울 동작구 상도동'
 *   (회원도 동까지 받는다 — 재고 등록 때 기본 주소로 쓴다, 2026-09-29. 남에게는 시군구까지만 보인다: User::regionName())
 *
 * 지역 목록 표는 두지 않는다. 주소 데이터는 카카오가 최신으로 유지하고, 우리는 고른 순간의 이름을 저장한다.
 */
trait PicksRegion
{
    public string $region_code = '';

    public string $region_name = '';

    /** 저장할 법정동 코드 자릿수. 8=읍면동 */
    protected function regionDigits(): int
    {
        return 8;
    }

    /**
     * 주소 검색 결과에서 필요한 것만 받는다. 브라우저가 보낸 값이라 형식을 다시 본다.
     *
     * @param  array{bcode?: string, sido?: string, sigungu?: string, bname?: string, bname1?: string}  $address
     */
    public function pickRegion(array $address): void
    {
        $bcode = (string) ($address['bcode'] ?? '');
        // 시·도 이름은 코드로 정한다. 모르는 코드(새로 생긴 시·도)일 때만 카카오가 준 글자를 쓴다
        $sido = Sido::name($bcode) ?? trim((string) ($address['sido'] ?? ''));

        if (! preg_match('/^\d{10}$/', $bcode) || $sido === '') {
            $this->addError('region_code', '주소에서 지역을 찾지 못했습니다. 다시 검색해 주세요.');

            return;
        }

        $parts = [$sido, $address['sigungu'] ?? ''];   // 세종은 시군구가 비어 있다
        if ($this->regionDigits() === 8) {
            // 리 지역이면 bname 이 '○○리', bname1 이 '○○면'이다. 읍면동까지만 남긴다
            $parts[] = ($address['bname1'] ?? '') ?: ($address['bname'] ?? '');
        }

        $this->region_code = substr($bcode, 0, $this->regionDigits());
        $this->region_name = implode(' ', array_filter(array_map(fn ($p) => mb_substr(trim((string) $p), 0, 20), $parts)));
        $this->resetErrorBag('region_code');
    }

    /**
     * 검증 규칙. 값은 pickRegion 만 채운다.
     * $keep_old: 예전 가입자의 시군구(5자리) 주소를 그대로 저장해도 되게 한다(정보 수정·자료 보완).
     * 주소를 다시 고르면 pickRegion 이 8자리를 넣는다.
     */
    protected function regionRules(bool $keep_old = false): array
    {
        return [
            'region_code' => ['required', 'regex:'.($keep_old ? '/^\d{5}(\d{3})?$/' : '/^\d{'.$this->regionDigits().'}$/')],
            'region_name' => ['required', 'string', 'max:40'],
        ];
    }

    protected function regionMessages(): array
    {
        return [
            'region_code.required' => '주소 검색으로 지역을 골라 주세요.',
            'region_code.regex' => '주소 검색으로 지역을 다시 골라 주세요.',
            'region_name.required' => '주소 검색으로 지역을 골라 주세요.',
        ];
    }
}
