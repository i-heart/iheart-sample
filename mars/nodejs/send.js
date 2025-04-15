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

    if (isRetry) {
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

  // 4. 알림톡 (기본형) 발송
  await axios.post(
      `${BASE_URL}/api/v1/send/alt`,
      {
        callback: "16442105",
        message: "안녕하세요, (주)아이하트입니다.\n해당 템플릿은 테스트건으로 승인 부탁드립니다.\n감사합니다.",
        receiverList: [
          {
            phone: "01001231234",
            userKey: "iheart-alt-1",
            customFields: {
              "이름": "김바른",
              "회사": "아이하트"
            }
          }
        ],
        title: "강조형 문구",
        senderKey: "fa14aa22ac69f174651d48d201111af25aac66e7",
        templateCode: "TEMPLATEJBJt20241118103614",
        type: "ALT",
        buttons: [
          {
            name: "버튼명",
            type: "WL",
            linkMo: "https://www.messent.co.kr",
            linkPc: "https://www.messent.co.kr"
          }
        ],
        fallback: {
          msgType: "SMS",
          message: "[대체문자] 안녕하세요 #{회사} #{이름}입니다."
        }
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

  // 5. 알림톡 (이미지형) 발송
  await axios.post(
      `${BASE_URL}/api/v1/send/alt`,
      {
        callback: "16442105",
        message: "등록테스트입니다.\n\n[아이하트 영업팀]\n\n#{이름}고객님의 적립금 소멸 예정 안내드립니다.\n\n※ 적립금은 마이페이지>적립금내역에서 자세한 확인 가능하며 이 메시지는 아이하트 회원에게만 발송됩니다.\n※ 이 메시지는 이용약관 동의에 따라 지급된 적립금 안내 메시지입니다.",
        receiverList: [
          {
            phone: "01001231234",
            userKey: "iheart-ali-1",
            customFields: {
              "이름": "김바른"
            }
          }
        ],
        senderKey: "00123c6160d2a054d336905ede205fd9b1524757",
        templateCode: "TEMPLATEyfcl20240925105620",
        type: "ALI",
        fallback: {
          "msgType": "MMS",
          "subject": "대체문자",
          "message": "[테스트] 알림톡 기본형 테스트입니다.",
          "fileIdList": [fileId],
        }
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
