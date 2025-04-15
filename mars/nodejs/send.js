const axios = require("axios");
const fs = require("fs");
const FormData = require("form-data");

const BASE_URL = "http://dev-poc-gw1-vnet.i-heart.kr:8000";
const FILE_PATH = "../assets/sample.jpg";

const user = {
  clientId: "rest_real_03",
  password: "dkdlgkxm1!",
};

(async () => {
  await sendMessage();
})();

async function sendMessage() {

  // 1. SMS 발송
  await fetchApi(() =>
      axios.post(
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
      )
  );

  // 2. LMS 발송
  await fetchApi(() =>
      axios.post(
          `${BASE_URL}/api/v1/send/mms`, // MMS API 사용, fileId 없으면 LMS
          {
            callback: "16442105",
            subject: "안내드립니다",
            message: "LMS 메시지입니다. #{이름} 고객님 확인 부탁드립니다.",
            receiverList: [
              {
                phone: "01001231234",
                userKey: "iheart-lms-1",
                customFields: {
                  "이름": "김찬란",
                },
              },
            ],
          },
          {
            headers: {
              Authorization: `Bearer ${user.accessToken}`,
              "Content-Type": "application/json; charset=utf-8",
            },
          }
      )
  );

  // 3. MMS 발송
  // 3-1. fileId 가져오기
  const {fileId} = await fetchApi(() => uploadFile("MMS"));

  // 3-2. MMS 발송 요청
  await fetchApi(() =>
      axios.post(
          `${BASE_URL}/api/v1/send/mms`,
          {
            callback: "16442105",
            subject: "알립니다",
            message: "#{이름}님의 이메일 주소가 #{이메일}인지 확인해 주세요.",
            receiverList: [
              {
                phone: "01001231234",
                userKey: "iheart-mms-1",
                customFields: {
                  "이름": "김바른",
                  "이메일": "iheart@i-heart.kr",
                },
              },
            ],
            fileIdList: [fileId],
          },
          {
            headers: {
              Authorization: `Bearer ${user.accessToken}`,
              "Content-Type": "application/json; charset=utf-8",
            },
          }
      )
  );
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

async function fetchApi(apiCallFn) {
  let attempt = 0;

  while (attempt < 2) {
    try {
      const {data} = await apiCallFn();
      console.log(data, "\n");
      return data;
    } catch (err) {
      const code = err.response?.data.code;

      if (code === "401" || code === "498" || attempt > 0) {
        console.log("⚠️ 토큰이 없거나 만료로 재인증 후 재시도 중...", "\n");
        user.accessToken = "";

        await authenticate();
        attempt++;
      } else {
        console.error(`❌ API 호출 실패 - code: ${code || 'unknown'}`);
        throw err;
      }
    }
  }
}

async function uploadFile(fileType) {
  const form = new FormData();
  form.append("filePart", fs.createReadStream(FILE_PATH));
  form.append("fileType", fileType);

  const {data} = await axios.post(`${BASE_URL}/api/v1/file`, form, {
    headers: {
      Authorization: `Bearer ${user.accessToken}`,
      ...form.getHeaders(),
    },
  });

  return data;
}
