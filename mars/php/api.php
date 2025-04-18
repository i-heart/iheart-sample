<?php

$BASE_URL = 'http://dev-poc-gw1-vnet.i-heart.kr:8000';
$CLIENT_ID = 'rest_real_03';
$PASSWORD = 'dkdlgkxm1!';
$token = null;

function auth()
{
    global $BASE_URL, $CLIENT_ID, $PASSWORD;

    $url = "$BASE_URL/api/v1/auth";
    $payload = json_encode([
        'clientId' => $CLIENT_ID,
        'password' => $PASSWORD
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => $payload
    ]);

    $res = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($res, true);
    return ($data['code'] === '100') ? $data['accessToken'] : null;
}

function sendRequest($method, $path, $data = null, $extraHeaders = [])
{
    global $BASE_URL, $token;

    $url = $BASE_URL . $path;

    $makeHeaders = function ($token) use ($extraHeaders) {
        return array_merge([
            "Authorization: Bearer $token",
            "Content-Type: application/json; charset=utf-8"
        ], $extraHeaders);
    };

    $executeCurl = function ($headers) use ($method, $url, $data) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $res = curl_exec($ch);
        curl_close($ch);

        return json_decode($res, true);
    };

    $headers = $makeHeaders($token);
    $decoded = $executeCurl($headers);

    if (in_array($decoded['code'] ?? '', ['401', '498'])) {
        echo "⚠️ 토큰이 없거나 만료로 재인증 시도중...\n\n";
        $token = auth();
        $headers = $makeHeaders($token);
        $decoded = $executeCurl($headers);
    }

    return $decoded;
}

function sendSms($payload)
{
    return sendRequest('POST', '/api/v1/send/sms', $payload);
}

function sendMms($payload)
{
    return sendRequest('POST', '/api/v1/send/mms', $payload);
}

function sendAlt($payload)
{
    return sendRequest('POST', '/api/v1/send/alt', $payload);
}

function sendRcs($payload)
{
    return sendRequest('POST', '/api/v1/send/rcs', $payload);
}

function uploadFile($filePath, $fileType = 'MMS')
{
    global $BASE_URL, $token;

    $url = "$BASE_URL/api/v1/file";

    $cfile = new CURLFile($filePath);
    $postFields = [
        'filePart' => $cfile,
        'fileType' => $fileType
    ];

    $executeCurl = function ($authToken) use ($url, $postFields) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $authToken"
            ],
            CURLOPT_POSTFIELDS => $postFields
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    };

    $data = $executeCurl($token);

    if (in_array($data['code'] ?? '', ['401', '498'])) {
        $token = auth();
        $data = $executeCurl($token);
    }

    return ($data['code'] === '100') ? $data['data']['fileId'] : null;
}

function polling()
{
    $headers = [];
    $rsltKey = null;

    for ($i = 0; $i < 5; $i++) {
        $data = sendRequest('GET', '/api/v1/report', null, $headers);
        echo "폴링 가능 여부 확인 중... (시도: " . ($i + 1) . "/5)\n";
        if ($data && isset($data['data'])) {
            break;
        }
        if ($i < 4) {
            sleep(5);
        } else {
            echo "최대 시도 횟수 도달. 폴링 실패.\n";
            return;
        }
    }

    while (true) {
        $path = '/api/v1/report';
        if ($rsltKey) {
            $path .= "/$rsltKey";
        }

        $data = sendRequest('GET', $path, null, $headers);
        print_r($data);

        $rsltKey = $data['data']['rsltKey'] ?? null;
        if (!$rsltKey) break;
    }
}

$smsPayload = [
    'callback' => '16442105',
    'message' => '안녕하세요. #{회사명} 소속 #{이름}입니다.',
    'receiverList' => [[
        'phone' => '01001231234',
        'userKey' => 'iheart-sms-1',
        'customFields' => [
            '이름' => '김바른',
            '회사명' => '아이하트'
        ]
    ]]
];

echo "SMS: ";
print_r(sendSms($smsPayload));

$lmsPayload = [
    'callback' => '16442105',
    'subject' => '안내드립니다',
    'message' => 'LMS 메시지입니다. #{이름} 고객님 확인 부탁드립니다.',
    'receiverList' => [[
        'phone' => '01001231234',
        'userKey' => 'iheart-lms-1',
        'customFields' => [
            '이름' => '김찬란'
        ]
    ]]
];

echo "LMS: ";
print_r(sendMms($lmsPayload));

$mmsFileId = uploadFile('../assets/sample.jpg', 'MMS');
$mmsPayload = [
    'callback' => '16442105',
    'subject' => '알립니다',
    'message' => '#{이름}님의 이메일 주소가 #{이메일}인지 확인해 주세요.',
    'receiverList' => [[
        'phone' => '01001231234',
        'userKey' => 'iheart-mms-1',
        'customFields' => [
            '이름' => '김바른',
            '이메일' => 'iheart@i-heart.kr'
        ]
    ]],
    'fileIdList' => [$mmsFileId] // 이 변수는 uploadFile 함수 결과로 받아야 함
];

echo "MMS File ID: $mmsFileId\n";
echo "MMS: ";
print_r(sendMms($mmsPayload));

$altPayload = [
    'callback' => '16442105',
    'message' => "안녕하세요, (주)아이하트입니다.\n해당 템플릿은 테스트건으로 승인 부탁드립니다.\n감사합니다.",
    'receiverList' => [[
        'phone' => '01001231234',
        'userKey' => 'iheart-alt-1',
        'customFields' => [
            '이름' => '김바른',
            '회사' => '아이하트'
        ]
    ]],
    'title' => '강조형 문구',
    'senderKey' => 'fa14aa22ac69f174651d48d201111af25aac66e7',
    'templateCode' => 'TEMPLATEJBJt20241118103614',
    'type' => 'ALT',
    'buttons' => [[
        'name' => '버튼명',
        'type' => 'WL',
        'linkMo' => 'https://www.messent.co.kr',
        'linkPc' => 'https://www.messent.co.kr'
    ]],
    'fallback' => [
        'msgType' => 'SMS',
        'message' => '[대체문자] 안녕하세요 #{회사} #{이름}입니다.'
    ]
];

echo "ALT: ";
print_r(sendAlt($altPayload));

$aliFileId = uploadFile('../assets/sample.jpg', 'MMS');
$aliPayload = [
    'callback' => '16442105',
    'message' => "등록테스트입니다.\n\n[아이하트 영업팀]\n\n#{이름}고객님의 적립금 소멸 예정 안내드립니다.",
    'receiverList' => [
        [
            'phone' => '01044104049',
            'userKey' => 'iheart-ali-1',
            'customFields' => [
                '이름' => '김바른'
            ]
        ]
    ],
    'senderKey' => '00123c6160d2a054d336905ede205fd9b1524757',
    'templateCode' => 'TEMPLATEyfcl20240925105620',
    'type' => 'ALI',
    'fallback' => [
        'msgType' => 'MMS',
        'subject' => '대체문자',
        'message' => '[테스트] 알림톡 기본형 테스트입니다.',
        'fileIdList' => [$aliFileId]
    ]
];

echo "ALI File ID: $aliFileId\n";
echo "ALI: ";
print_r(sendAlt($aliPayload));

$rcsPayload = [
    'callback' => '16442105',
    'subject' => '메시지 제목',
    'message' => '안녕하세요. #{주소}에 사는 #{대상자} 입니다.',
    'buttons' => [
        [
            'type' => 'URL',
            'name' => '버튼명',
            'url' => 'https://www.messent.co.kr'
        ]
    ],
    'receiverList' => [
        [
            'phone' => '01044104049',
            'userKey' => 'iheart-rcs-1',
            'customFields' => [
                '대상자' => '정의진',
                '주소' => '서울특별시 금천구'
            ]
        ]
    ],
    'agencyId' => 'IHEART',
    'agencyKey' => 'AK.eEt1RjNBZBP1xjC',
    'brandId' => 'BR.m4nxVh6sf4',
    'brandKey' => 'BK.q01CWxkZ2KO2Y9d',
    'messageBaseId' => 'SS000000',
    'isCopy' => 'Y',
    'expiryOpt' => '2',
    'header' => '1',
    'footer' => '0800000000',
    'fallback' => [
        'msgType' => 'SMS',
        'message' => '안녕하세요. #{주소}에 사는 #{대상자} 입니다.'
    ]
];

echo "RCS: ";
print_r(sendRcs($rcsPayload));

$rclPayload = [
    'callback' => '16442105',
    'subject' => '메시지 제목',
    'message' => "안녕하세요, 고객님. 저희 서비스를 이용해주셔서 진심으로 감사드립니다.\n이번 달에도 다양한 혜택과 이벤트가 준비되어 있으니, 자세한 내용은 홈페이지를 통해 확인해 주세요.\n항상 최선을 다하는 브랜드가 되겠습니다. 감사합니다.",
    'buttons' => [
        [
            'type' => 'URL',
            'name' => '버튼명',
            'url' => 'https://www.messent.co.kr'
        ]
    ],
    'receiverList' => [
        [
            'phone' => '01044104049',
            'userKey' => 'iheart-rcl-1'
        ]
    ],
    'agencyId' => 'IHEART',
    'agencyKey' => 'AK.eEt1RjNBZBP1xjC',
    'brandId' => 'BR.m4nxVh6sf4',
    'brandKey' => 'BK.q01CWxkZ2KO2Y9d',
    'messageBaseId' => 'SL000000',
    'isCopy' => 'Y',
    'expiryOpt' => '2',
    'header' => '1',
    'footer' => '0800000000',
    'fallback' => [
        'msgType' => 'SMS',
        'message' => '안녕하세요, 고객님. 대체문자 발송드립니다.'
    ]
];

echo "RCL: ";
print_r(sendRcs($rclPayload));

$rcmFileId = uploadFile('../assets/sample.jpg', 'RCS');
$rcmPayload = [
    'callback' => '16442105',
    'subject' => '안내사항',
    'message' => '안녕하세요, 고객님. 저희 서비스를 이용해주셔서 진심으로 감사드립니다.',
    'fileId' => $rcmFileId,
    'buttons' => [
        [
            'type' => 'URL',
            'name' => '버튼명',
            'url' => 'https://www.messent.co.kr'
        ]
    ],
    'receiverList' => [
        [
            'phone' => '01044104049',
            'userKey' => 'iheart-rcm-1'
        ]
    ],
    'agencyId' => 'IHEART',
    'agencyKey' => 'AK.eEt1RjNBZBP1xjC',
    'brandId' => 'BR.m4nxVh6sf4',
    'brandKey' => 'BK.q01CWxkZ2KO2Y9d',
    'messageBaseId' => 'SMwThT00',
    'isCopy' => 'Y',
    'expiryOpt' => '2',
    'header' => '1',
    'footer' => '0800000000'
];

echo "RCM File ID: $rcmFileId\n";
echo "RCM: ";
print_r(sendRcs($rcmPayload));

polling();