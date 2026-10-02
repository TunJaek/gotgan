<?php

return [
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),   // log | sens
        'service_id' => env('SENS_SERVICE_ID'),
        'access_key' => env('SENS_ACCESS_KEY'),
        'secret_key' => env('SENS_SECRET_KEY'),
        'from' => env('SENS_FROM'),
    ],

    // 이용약관·개인정보처리방침의 지금 판. 본문은 resources/legal/{문서}-v{판}.md.
    // 내용을 고치면 파일을 새로 만들고(v2) 여기 번호를 올린다. 이전 판 파일은 지우지 않는다.
    'legal' => [
        'terms' => ['version' => 1],
        'privacy' => ['version' => 1],
    ],

    // 휴대폰 본인인증(다날 PASS). app/Support/Pass.php
    'pass' => [
        'driver' => env('PASS_DRIVER', 'log'),   // log(개발용 가짜 인증) | danal
        'cpid' => env('DANAL_CPID'),
        'cppwd' => env('DANAL_CPPWD'),
        // UAS CONFIRM 응답 항목. USERID 는 요청 때 IsDstAddr 로 고정한 휴대폰 번호와 항상 같아야 한다.
        'fields' => ['phone' => 'USERID', 'name' => 'NAME', 'ci' => 'CI'],
    ],

    'bizno' => [
        'driver' => env('BIZNO_DRIVER', 'log'), // log | nts
        'api_key' => env('BIZNO_API_KEY'),
    ],
];
