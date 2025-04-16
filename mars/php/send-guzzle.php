<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

$BASE_URL = "http://dev-poc-gw1-vnet.i-heart.kr:8000";
$FILE_PATH = "../assets/sample.jpg";

$user = [
    "clientId" => "minoflower",
    "password" => "dkdlgkxm1!",
    "accessToken" => ""
];

$stack = HandlerStack::create();

$retryMiddleware = Middleware::retry(function (
    int $retries,
    Request $request,
    ?Response $response = null,
    ?RequestException $exception = null
) use (&$user) {
    if ($response === null) {
        return false;
    }

    $statusCode = $response->getStatusCode();
    $body = json_decode($response->getBody(), true);
    $code = $body['code'] ?? '';

    $shouldRetry = in_array($code, ['401', '498']);

    if ($shouldRetry) {
        echo "⚠️ 토큰이 없거나 만료로 재인증 시도중...\n\n";
        $user['accessToken'] = "";
        authenticate($user);  // 토큰 갱신
    }

    return $shouldRetry && $retries < 1; // 1회만 재시도
});

// 요청마다 최신 토큰 반영
$authHeaderMiddleware = Middleware::mapRequest(function (RequestInterface $request) use (&$user) {
    if (!empty($user['accessToken'])) {
        return $request->withHeader('Authorization', "Bearer {$user['accessToken']}");
    }
    return $request;
});

$stack->push($retryMiddleware);
$stack->push($authHeaderMiddleware);  // 반드시 retryMiddleware 다음에 push

$client = new Client([
    'handler' => $stack,
    'base_uri' => $BASE_URL
]);

/**
 * @throws GuzzleException
 */
function authenticate(array &$user): void {
    global $client;

    if (empty($user['accessToken'])) {
        $response = $client->post('/api/v1/auth', [
            'json' => [
                'clientId' => $user['clientId'],
                'password' => $user['password']
            ]
        ]);

        $data = json_decode($response->getBody(), true);
        $code = $data['code'] ?? '';
        $accessToken = $data['accessToken'] ?? '';

        echo json_encode($data) . "\n\n";

        if ($code === "100") {
            $user['accessToken'] = $accessToken;
        }
    }
}

/**
 * @throws GuzzleException
 */
function uploadFile(string $fileType, array &$user): ?array {
    global $client, $FILE_PATH;

    if (empty($user['accessToken'])) {
        authenticate($user);
    }

    $multipart = [
        [
            'name' => 'filePart',
            'contents' => fopen($FILE_PATH, 'r')
        ],
        [
            'name' => 'fileType',
            'contents' => $fileType
        ]
    ];

    $response = $client->post('/api/v1/file', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}"
        ],
        'multipart' => $multipart
    ]);

    $data = json_decode($response->getBody(), true);
    echo json_encode($data) . "\n";

    return $data['data'] ?? null;
}

/**
 * @throws GuzzleException
 */
function sendMessage(): void {
    global $client, $user;

    // 1. SMS 발송
    $response = $client->post('/api/v1/send/sms', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}",
            'Content-Type' => 'application/json; charset=utf-8'
        ],
        'json' => [
            'callback' => '16442105',
            'message' => '안녕하세요. #{회사명} 소속 #{이름}입니다.',
            'receiverList' => [
                [
                    'phone' => '01044104049',
                    'userKey' => 'iheart-sms-1',
                    'customFields' => [
                        '이름' => '김바른',
                        '회사명' => '아이하트'
                    ]
                ]
            ]
        ]
    ]);

    $data = json_decode($response->getBody(), true);
    echo json_encode($data) . "\n";

    // 2. LMS 발송
    $response = $client->post('/api/v1/send/mms', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}",
            'Content-Type' => 'application/json; charset=utf-8'
        ],
        'json' => [
            'callback' => '16442105',
            'subject' => '안내드립니다',
            'message' => 'LMS 메시지입니다. #{이름} 고객님 확인 부탁드립니다.',
            'receiverList' => [
                [
                    'phone' => '01044104049',
                    'userKey' => 'iheart-lms-1',
                    'customFields' => [
                        '이름' => '김찬란'
                    ]
                ]
            ]
        ]
    ]);

    $data = json_decode($response->getBody(), true);
    echo json_encode($data) . "\n";

    // 3. MMS 발송
    // 3-1. fileId 가져오기
    $uploadResult = uploadFile("MMS", $user);
    $mmsFileId = null;
    if (is_array($uploadResult) && isset($uploadResult['fileId'])) {
        $mmsFileId = $uploadResult['fileId'];
    }

    // 3-2. MMS 발송 요청
    $response = $client->post('/api/v1/send/mms', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}",
            'Content-Type' => 'application/json; charset=utf-8'
        ],
        'json' => [
            'callback' => '16442105',
            'subject' => '알립니다',
            'message' => '#{이름}님의 이메일 주소가 #{이메일}인지 확인해 주세요.',
            'receiverList' => [
                [
                    'phone' => '01044104049',
                    'userKey' => 'iheart-mms-1',
                    'customFields' => [
                        '이름' => '김바른',
                        '이메일' => 'iheart@i-heart.kr'
                    ]
                ]
            ],
            'fileIdList' => [$mmsFileId]
        ]
    ]);

    $data = json_decode($response->getBody(), true);
    echo json_encode($data) . "\n";

    // 4. 알림톡 (기본형) 발송
    $response = $client->post('/api/v1/send/alt', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}",
            'Content-Type' => 'application/json; charset=utf-8'
        ],
        'json' => [
            'callback' => '16442105',
            'message' => "안녕하세요, (주)아이하트입니다.\n해당 템플릿은 테스트건으로 승인 부탁드립니다.\n감사합니다.",
            'receiverList' => [
                [
                    'phone' => '01044104049',
                    'userKey' => 'iheart-alt-1',
                    'customFields' => [
                        '이름' => '김바른',
                        '회사' => '아이하트'
                    ]
                ]
            ],
            'title' => '강조형 문구',
            'senderKey' => 'fa14aa22ac69f174651d48d201111af25aac66e7',
            'templateCode' => 'TEMPLATEJBJt20241118103614',
            'type' => 'ALT',
            'buttons' => [
                [
                    'name' => '버튼명',
                    'type' => 'WL',
                    'linkMo' => 'https://www.messent.co.kr',
                    'linkPc' => 'https://www.messent.co.kr'
                ]
            ],
            'fallback' => [
                'msgType' => 'SMS',
                'message' => '[대체문자] 안녕하세요 #{회사} #{이름}입니다.'
            ]
        ]
    ]);

    $data = json_decode($response->getBody(), true);
    echo json_encode($data) . "\n";

    // 5. 알림톡 (이미지형) 발송
    // 5-1. fileId 가져오기 (MMS 대체문자 발송 case)
    $uploadResult = uploadFile("MMS", $user);
    $altFallbackFileId = null;
    if (is_array($uploadResult) && isset($uploadResult['fileId'])) {
        $altFallbackFileId = $uploadResult['fileId'];
    }

    // 5-2. 알림톡 발송 요청
    $response = $client->post('/api/v1/send/alt', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}",
            'Content-Type' => 'application/json; charset=utf-8'
        ],
        'json' => [
            'callback' => '16442105',
            'message' => "등록테스트입니다.\n\n[아이하트 영업팀]\n\n#{이름}고객님의 적립금 소멸 예정 안내드립니다.\n\n※ 적립금은 마이페이지>적립금내역에서 자세한 확인 가능하며 이 메시지는 아이하트 회원에게만 발송됩니다.\n※ 이 메시지는 이용약관 동의에 따라 지급된 적립금 안내 메시지입니다.",
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
                'fileIdList' => [$altFallbackFileId]
            ]
        ]
    ]);

    $data = json_decode($response->getBody(), true);
    echo json_encode($data) . "\n";

    // 6. RCS 단문 발송
    $response = $client->post('/api/v1/send/rcs', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}",
            'Content-Type' => 'application/json; charset=utf-8'
        ],
        'json' => [
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
        ]
    ]);

    $data = json_decode($response->getBody(), true);
    echo json_encode($data) . "\n";

    // 7. RCS 장문 발송
    $response = $client->post('/api/v1/send/rcs', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}",
            'Content-Type' => 'application/json; charset=utf-8'
        ],
        'json' => [
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
        ]
    ]);

    $data = json_decode($response->getBody(), true);
    echo json_encode($data) . "\n";

    // 8. RCS 멀티 발송
    // 8-1. fileId 가져오기
    $uploadResult = uploadFile("RCS", $user);
    $rcmFileId = null;
    if (is_array($uploadResult) && isset($uploadResult['fileId'])) {
        $rcmFileId = $uploadResult['fileId'];
    }

    // 8-2. RCS 멀티 발송 요청
    $response = $client->post('/api/v1/send/rcs', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}",
            'Content-Type' => 'application/json; charset=utf-8'
        ],
        'json' => [
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
        ]
    ]);

    $data = json_decode($response->getBody(), true);
    echo json_encode($data) . "\n";
}

try {
    sendMessage();
} catch (GuzzleException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>