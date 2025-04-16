const axios = require("axios");
const axiosRetry = require("axios-retry").default;

const BASE_URL = "http://dev-poc-gw1-vnet.i-heart.kr:8000";

const user = {
  clientId: "minoflower",
  password: "dkdlgkxm1!",
};

const pollingConfig = {
  maxAttempts: 5, // 최대 폴링 시도 횟수 (규격서와 무관함)
  interval: 5000, // 폴링 간격 (ms)
}

axiosRetry(axios, {
  retries: 1,
  retryCondition: async (error) => {
    const code = error.response?.data?.code;
    let isRetry = ["401", "498"].includes(code);

    if (isRetry) {
      console.log("⚠️ 토큰이 없거나 만료로 재인증 시도중...", "\n");
      user.accessToken = "";

      await authenticate();

      error.config.headers['Authorization'] = `Bearer ${user.accessToken}`
    }

    return isRetry;
  }
});

// 폴링을 수행하기 위한 발송
async function sendMessage() {
  // 1. SMS 발송
  await axios.post(
      `${BASE_URL}/api/v1/send/sms`,
      {
        callback: "16442105",
        message: "안녕하세요. #{회사명} 소속 #{이름}입니다.",
        receiverList: [
          {
            phone: "01001231234",
            userKey: "iheart-sms-1",
            customFields: {
              "이름": "김바른",
              "회사명": "아이하트",
            },
          },
        ],
      },
      {
        headers: {
          "Authorization": `Bearer ${user.accessToken}`,
          "Content-Type": "application/json; charset=utf-8",
        },
      }
  ).then(response => {
    console.log(response.data);
  });
}

async function polling() {
  // 폴링 최초 요청
  const firstResponse = await axios.get(
      `${BASE_URL}/api/v1/report`,
      {
        headers: {
          "Authorization": `Bearer ${user.accessToken}`,
          "Content-Type": "application/json; charset=utf-8",
        },
      }
  );

  console.log("폴링 최초 요청 결과\n", firstResponse.data);
  let rsltKey = firstResponse.data?.data?.rsltKey;

  // 폴링 다음 요청
  while (rsltKey) {
    const response = await axios.get(
        `${BASE_URL}/api/v1/report/${rsltKey}`,
        {
          headers: {
            "Authorization": `Bearer ${user.accessToken}`,
            "Content-Type": "application/json; charset=utf-8",
          },
        }
    );

    console.log("폴링 다음 요청 결과\n", response.data);

    // rslyKey 갱신. 없으면 종료
    rsltKey = response.data?.rsltKey;
  }
}

async function authenticate() {
  if (!user.accessToken) {
    const {clientId, password} = user;
    const {data} = await axios.post(`${BASE_URL}/api/v1/auth`,
        {clientId, password});
    const {code, accessToken} = data;

    console.log(data, "\n");

    if (code === "100") {
      user.accessToken = accessToken;
    }
  }
}

async function checkPollingAvailable() {
  const response = await axios.get(
      `${BASE_URL}/api/v1/report`,
      {
        headers: {
          "Authorization": `Bearer ${user.accessToken}`,
          "Content-Type": "application/json; charset=utf-8",
        },
      }
  );

  return !!response.data?.data?.rsltKey;
}

function delay(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

(async () => {
  await sendMessage();

  let attempt = 1;
  while (attempt <= pollingConfig.maxAttempts) {
    const canPolling = await checkPollingAvailable();

    if (canPolling) {
      await polling();
      break;
    }

    console.log(`폴링을 위한 요청중... (시도: ${attempt}/${pollingConfig.maxAttempts})`);

    if (attempt < pollingConfig.maxAttempts) {
      await delay(pollingConfig.interval); // 5초 대기
    } else {
      console.log("최대 시도 횟수에 도달했습니다. 폴링 실패.");
    }

    attempt++;
  }
})();

