const axios = require("axios");
const axiosRetry = require("axios-retry").default;
const fs = require("fs");
const FormData = require("form-data");

const BASE_URL = "http://dev-poc-gw1-vnet.i-heart.kr:8000";
const FILE_PATH = "../assets/sample.jpg";

const user = {
  clientId: "rest_real_03",
  password: "dkdlgkxm1!",
};

axiosRetry(axios, {
  retries: 1,
  retryCondition: async (error) => {
    const code = error.response?.data?.code;
    let isRetry = ["401", "498"].includes(code);

    if(isRetry) {
      console.log("⚠️ 토큰이 없거나 만료로 재인증 시도중...", "\n");
      user.accessToken = "";

      await authenticate();

      error.config.headers['Authorization'] = `Bearer ${user.accessToken}`
    }

    return isRetry;
  }
})

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

  // 2. LMS 발송
  await axios.post(
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
  ).then(response => {
    console.log(response.data);
  });

  // 3. MMS 발송
  // 3-1. fileId 가져오기
  const {fileId} = await uploadFile("MMS");

  // 3-2. MMS 발송 요청
  await axios.post(
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
  ).then(response => {
    console.log(response.data);
  });
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

  console.log(data);

  return data.data;
}

(async () => {
  await sendMessage();
})();
