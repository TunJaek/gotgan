<?php

namespace Tests\Feature;

use Livewire\Livewire;
use Tests\TestCase;

/** 주소 검색 결과에서 지역 코드·이름만 남기는지(App\Support\PicksRegion) */
class RegionTest extends TestCase
{
    public function test_회원_사업장도_읍면동까지_짧은_시도_이름으로_남는다(): void
    {
        Livewire::test('auth.register')
            ->call('pickRegion', ['bcode' => '1159010200', 'sido' => '서울특별시', 'sigungu' => '동작구', 'bname' => '상도동', 'bname1' => ''])
            ->assertSet('region_code', '11590102')
            ->assertSet('region_name', '서울 동작구 상도동')
            ->call('pickRegion', ['bcode' => '3611010100', 'sido' => '세종특별자치시', 'sigungu' => '', 'bname' => '반곡동'])
            ->assertSet('region_code', '36110101')
            ->assertSet('region_name', '세종 반곡동');
    }

    public function test_시도_이름은_보낸_글자가_아니라_코드로_정한다(): void
    {
        Livewire::test('auth.register')
            ->call('pickRegion', ['bcode' => '1159010200', 'sido' => '아무거나', 'sigungu' => '동작구'])
            ->assertSet('region_name', '서울 동작구')     // 동 이름이 안 오면 시군구까지
            ->call('pickRegion', ['bcode' => '9911010100', 'sido' => '새로운특별시', 'sigungu' => '가구'])   // 모르는 코드는 막지 않는다
            ->assertSet('region_code', '99110101')
            ->assertSet('region_name', '새로운특별시 가구');
    }

    public function test_코드가_이상하면_받지_않는다(): void
    {
        Livewire::test('auth.register')
            ->call('pickRegion', ['bcode' => '1159', 'sido' => '서울', 'sigungu' => '동작구'])
            ->assertHasErrors('region_code')
            ->assertSet('region_code', '')
            ->call('pickRegion', ['bcode' => '9911010100', 'sido' => '', 'sigungu' => '가구'])   // 모르는 코드인데 이름도 없다
            ->assertHasErrors('region_code');
    }

    public function test_다른_회원에게는_시군구까지_본인에게는_동까지_보인다(): void
    {
        $u = new \App\Models\User(['region_code' => '11590102', 'region_name' => '서울 동작구 상도동']);
        $this->assertSame('서울 동작구', $u->regionName());
        $this->assertSame('서울 동작구 상도동', $u->regionName(full: true));

        $old = new \App\Models\User(['region_code' => '11590', 'region_name' => '서울 동작구']);   // 예전 가입자
        $this->assertSame('서울 동작구', $old->regionName());
    }
}
