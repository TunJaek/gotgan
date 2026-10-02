<?php

/**
 * 검증 실패 메시지. 지금 앱에서 쓰는 규칙만 적어 두었다.
 * 규칙을 새로 쓰기 시작하면 여기에 한 줄 추가한다. 없으면 'validation.규칙이름' 이 그대로 화면에 나온다.
 *
 * :attribute 자리에는 아래 attributes 의 한글 이름이 들어간다.
 */
return [

    // 한국어는 앞말 받침에 따라 조사가 달라지는데 라라벨이 그걸 못 고른다.
    // '제목은(는)' 처럼 쓰지 않으려고 조사가 필요 없는 문형으로 적었다.
    'accepted'              => ':attribute — 동의가 필요합니다.',
    'array'                 => ':attribute — 형식이 올바르지 않습니다.',
    'boolean'               => ':attribute — 형식이 올바르지 않습니다.',
    'email'                 => ':attribute — 형식이 올바르지 않습니다.',
    'file'                  => ':attribute — 파일을 올려 주세요.',
    'image'                 => ':attribute — 이미지 파일만 올릴 수 있습니다.',
    'in'                    => ':attribute — 선택할 수 없는 값입니다.',
    'integer'               => ':attribute — 숫자로 입력해 주세요.',
    'mimes'                 => ':attribute — :values 형식만 올릴 수 있습니다.',
    'regex'                 => ':attribute — 형식이 올바르지 않습니다.',
    'required'              => ':attribute — 필수 입력입니다.',
    'required_if'           => ':attribute — 필수 입력입니다.',
    'after_or_equal'        => ':attribute — 오늘 이후 날짜로 골라 주세요.',
    'string'                => ':attribute — 형식이 올바르지 않습니다.',
    'date'                  => ':attribute — 형식이 올바르지 않습니다.',

    // 대상이 글자냐 숫자냐 파일이냐에 따라 문구가 달라진다.
    'min' => [
        'array'   => ':attribute — :min개 이상 선택해 주세요.',
        'file'    => ':attribute — :min KB 이상이어야 합니다.',
        'numeric' => ':attribute — :min 이상이어야 합니다.',
        'string'  => ':attribute — :min자 이상 입력해 주세요.',
    ],

    'max' => [
        'array'   => ':attribute — :max개까지 선택할 수 있습니다.',
        'file'    => ':attribute — :max KB 이하만 올릴 수 있습니다.',
        'numeric' => ':attribute — :max 이하여야 합니다.',
        'string'  => ':attribute — :max자까지 입력할 수 있습니다.',
    ],

    /**
     * 필드 이름을 화면에 쓰는 말로 바꾼다. 여기 없으면 'price_amount' 같은 컬럼명이 그대로 나온다.
     */
    'attributes' => [
        // 재고 등록
        'title'             => '제목',
        'photos'            => '사진',
        'photos.*'          => '사진',
        'category_id'       => '카테고리',
        'condition'         => '물품 상태',
        'description'       => '물품 설명',
        'price_mode'        => '가격 표시',
        'price_amount'      => '판매 가격',
        'price_negotiable'  => '협의 가능 표시',
        'vat_mode'          => '부가세 표시',
        'region_code'       => '물품 소재 지역',
        'budget_amount'     => '예산',
        'listed_until'           => '게시 마감일',
        'report_reason'     => '신고 사유',

        // 문의
        'inquiry_message'   => '문의 내용',
        'draft'             => '메시지 내용',

        // 회원가입·로그인
        'bizNumber'        => '사업자등록번호',
        'companyName'      => '상호',
        'ownerName'        => '대표자명',
        'phone'            => '휴대폰 번호',
        'email'            => '이메일',
        'password'         => '비밀번호',
        'license'          => '사업자등록증',
        'industry'         => '업종',
        'regionCode'       => '지역',
        'agreeTerms'       => '이용약관',
        'agreePrivacy'     => '개인정보 수집·이용',
    ],
    'custom' => [
        'region_code' => [
            'regex' => '물품 소재 지역 — 지역명을 한글로 입력해 주세요. (예: 경기 성남시)',
        ],
    ],
];
