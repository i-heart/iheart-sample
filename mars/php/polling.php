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

$pollingConfig = [
    'maxAttempts' => 5, // 최대 폴링 시도 횟수 (규격서와 무관함)
    'interval' => 5000, // 폴링 간격 (ms)
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

// 메시지 발송 함수
/**
 * @throws GuzzleException
 */
function sendMessage(): void {
    global $client, $user;

    $response = $client->post('/api/v1/send/sms', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}"
        ],
        'json' => [
            'callback' => '16442105',
            'message' => '안녕하세요. #{회사명} 소속 #{이름}입니다.',
            'receiverList' => [
                [
                    'phone' => '01001231234',
                    'userKey' => 'iheart-sms-1',
                    'customFields' => [
                        '이름' => '김바른',
                        '회사명' => '아이하트'
                    ]
                ]
            ]
        ]
    ]);

    $responseData = json_decode($response->getBody(), true);
    echo json_encode($responseData) . "\n";
}

// 폴링 함수
/**
 * @throws GuzzleException
 */
function polling(): void {
    global $client, $user;

    // 폴링 최초 요청
    $firstResponse = $client->get('/api/v1/report', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}"
        ]
    ]);

    $firstData = json_decode($firstResponse->getBody(), true);
    echo "폴링 최초 요청 결과\n" . json_encode($firstData) . "\n";

    $rsltKey = $firstData['data']['rsltKey'] ?? null;

    // 폴링 다음 요청
    while ($rsltKey) {
        $response = $client->get("/api/v1/report/{$rsltKey}", [
            'headers' => [
                'Authorization' => "Bearer {$user['accessToken']}"
            ]
        ]);

        $data = json_decode($response->getBody(), true);
        echo "폴링 다음 요청 결과\n" . json_encode($data) . "\n";

        // rsltKey 갱신. 없으면 종료
        $rsltKey = $data['rsltKey'] ?? null;
    }
}

// 폴링 가능 여부 확인
/**
 * @throws GuzzleException
 */
function checkPollingAvailable(): bool {
    global $client, $user;

    $response = $client->get('/api/v1/report', [
        'headers' => [
            'Authorization' => "Bearer {$user['accessToken']}"
        ]
    ]);

    $data = json_decode($response->getBody(), true);
    return !empty($data['data']['rsltKey']);
}

// 딜레이 함수
function delay(int $ms): void {
    usleep($ms * 1000); // microseconds로 변환
}

// 메인 실행 함수
(function() {
    // 메시지 전송
    sendMessage();

    global $pollingConfig;

    $attempt = 1;
    while ($attempt <= $pollingConfig['maxAttempts']) {
        $canPolling = checkPollingAvailable();

        if ($canPolling) {
            polling();
            break;
        }

        echo "폴링을 위한 요청중... (시도: {$attempt}/{$pollingConfig['maxAttempts']})\n";

        if ($attempt < $pollingConfig['maxAttempts']) {
            delay($pollingConfig['interval']);
        } else {
            echo "최대 시도 횟수에 도달했습니다. 폴링 실패.\n";
        }

        $attempt++;
    }
})();
?>