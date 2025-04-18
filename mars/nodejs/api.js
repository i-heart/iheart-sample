const axios = require('axios');
const fs = require('fs');
const FormData = require('form-data');

const BASE_URL = 'http://dev-poc-gw1-vnet.i-heart.kr:8000';
const CLIENT_ID = 'rest_real_03';
const PASSWORD = 'dkdlgkxm1!';
let token = null;

async function auth() {
  const url = `${BASE_URL}/api/v1/auth`;
  const payload = {
    clientId: CLIENT_ID,
    password: PASSWORD
  };

  try {
    const res = await axios.post(url, payload, {
      headers: {'Content-Type': 'application/json; charset=utf-8'}
    });

    if (res.data.code === '100') {
      return res.data.accessToken;
    }
  } catch (err) {
    console.error('인증 실패:', err.message);
  }

  return null;
}

async function sendRequest(method, path, data = null, headers = {}) {
  headers['Authorization'] = `Bearer ${token}`;
  headers['Content-Type'] = 'application/json; charset=utf-8';

  const url = `${BASE_URL}${path}`;

  let res = await axios({
    method,
    url,
    headers,
    data
  }).catch(async err => {
    const code = err.response?.data?.code;
    if (['401', '498'].includes(code)) {
      console.log("⚠️ 토큰이 없거나 만료로 재인증 시도중...\n\n");
      token = await auth();

      headers['Authorization'] = `Bearer ${token}`;
      let res = await axios({
        method,
        url,
        headers,
        data
      });

      return res.data;
    }

    console.error(`요청 실패 [${method}] ${url}`, err.message);
    return null;
  });

  return res.data;
}

function sendSms(payload) {
  return sendRequest('POST', `/api/v1/send/sms`, payload);
}

function sendMms(payload) {
  return sendRequest('POST', `/api/v1/send/mms`, payload);
}

function sendAlt(payload) {
  return sendRequest('POST', `/api/v1/send/alt`, payload);
}

function sendRcs(payload) {
  return sendRequest('POST', `/api/v1/send/rcs`, payload);
}

async function uploadFile(filePath, fileType = 'MMS') {
  const url = `${BASE_URL}/api/v1/file`;

  const createForm = () => {
    const form = new FormData();
    form.append('filePart', fs.createReadStream(filePath));
    form.append('fileType', fileType);
    return form;
  };

  let form = createForm();
  let headers = {
    ...form.getHeaders(),
    Authorization: `Bearer ${token}`
  }

  const res = await axios.post(url, form, {headers}).catch(async err => {
    const code = err.response?.data?.code;
    if (['401', '498'].includes(code)) {
      console.log("⚠️ 토큰이 없거나 만료로 재인증 시도중...\n\n");
      token = await auth();

      form = createForm();
      headers = {
        ...form.getHeaders(),
        Authorization: `Bearer ${token}`
      }

      return await axios.post(url, form, {headers});
    }

    console.error(`파일 업로드 실패 [POST] ${url}`, err.message);
    return null;
  });

  return (res.data.code === '100') ? res.data.data.fileId : null;
}

async function polling() {
  const headers = {'Content-Type': 'application/json; charset=utf-8'};
  let rsltKey = null;

  for (let i = 0; i < 5; i++) {
    const data = await sendRequest('GET', '/api/v1/report',
        null, headers);
    console.log(`폴링 가능 여부 확인 중... (시도: ${i + 1}/5)`);
    if (data && data.data) {
      break;
    }
    if (i < 4) {
      await new Promise(resolve => setTimeout(resolve, 5000));
    } else {
      console.warn("최대 시도 횟수 도달. 폴링 실패.");
      return;
    }
  }

  while (true) {
    let path = `/api/v1/report`;
    if (rsltKey) {
      path += `/${rsltKey}`;
    }
    const data = await sendRequest('GET', path, null, headers);
    console.log(data);

    rsltKey = data?.data?.rsltKey;
    if (!rsltKey) {
      break;
    }
  }
}

(async () => {

  const smsPayload = {
    callback: '16442105',
    message: '안녕하세요. #{회사명} 소속 #{이름}입니다.',
    receiverList: [{
      phone: '01001231234',
      userKey: 'iheart-sms-1',
      customFields: {
        "이름": '김바른',
        "회사명": '아이하트'
      }
    }]
  };

  console.log('SMS: ', await sendSms(smsPayload));

  const lmsPayload = {
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
  };

  console.log('LMS: ', await sendMms(lmsPayload));

  const mmsFileId = await uploadFile("../assets/sample.jpg", "MMS");
  const mmsPayload = {
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
    fileIdList: [mmsFileId],
  }

  console.log('MMS fileId: ', mmsFileId);
  console.log('MMS: ', await sendMms(mmsPayload));

  const altPayload = {
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
  }

  console.log('ALT: ', await sendAlt(altPayload));

  const aliFileId = await uploadFile("../assets/sample.jpg", "MMS");
  const aliPayload = {
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
      "fileIdList": [aliFileId],
    }
  }

  console.log('ALI fileId: ', aliFileId);
  console.log('ALI: ', await sendAlt(aliPayload));

  const rcsPayload = {
    callback: "16442105",
    subject: "메시지 제목",
    message: "안녕하세요. #{주소}에 사는 #{대상자} 입니다.",
    buttons: [
      {
        type: "URL",
        name: "버튼명",
        url: "https://www.messent.co.kr"
      }
    ],
    receiverList: [
      {
        phone: "01001231234",
        userKey: "iheart-rcs-1",
        customFields: {
          "대상자": "정의진",
          "주소": "서울특별시 금천구"
        }
      }
    ],
    agencyId: "IHEART",
    agencyKey: "AK.eEt1RjNBZBP1xjC",
    brandId: "BR.m4nxVh6sf4",
    brandKey: "BK.q01CWxkZ2KO2Y9d",
    messageBaseId: "SS000000",
    isCopy: "Y",
    expiryOpt: "2",
    header: "1",
    footer: "0800000000",
    fallback: {
      msgType: "SMS",
      message: "안녕하세요. #{주소}에 사는 #{대상자} 입니다."
    }
  }

  console.log('RCS: ', await sendRcs(rcsPayload));

  const rclPayload = {
    callback: "16442105",
    subject: "메시지 제목",
    message: "안녕하세요, 고객님. 저희 서비스를 이용해주셔서 진심으로 감사드립니다.\n이번 달에도 다양한 혜택과 이벤트가 준비되어 있으니, 자세한 내용은 홈페이지를 통해 확인해 주세요.\n항상 최선을 다하는 브랜드가 되겠습니다. 감사합니다.",
    buttons: [
      {
        type: "URL",
        name: "버튼명",
        url: "https://www.messent.co.kr"
      }
    ],
    receiverList: [
      {
        phone: "01001231234",
        userKey: "iheart-rcl-1",
      }
    ],
    agencyId: "IHEART",
    agencyKey: "AK.eEt1RjNBZBP1xjC",
    brandId: "BR.m4nxVh6sf4",
    brandKey: "BK.q01CWxkZ2KO2Y9d",
    messageBaseId: "SL000000",
    isCopy: "Y",
    expiryOpt: "2",
    header: "1",
    footer: "0800000000",
    fallback: {
      msgType: "SMS",
      message: "안녕하세요, 고객님. 대체문자 발송드립니다."
    }
  };

  console.log('RCL: ', await sendRcs(rclPayload));

  const rcmFileId = await uploadFile("../assets/sample.jpg", "RCS");
  const rcmPayload = {
    callback: "16442105",
    subject: "안내사항",
    message: "안녕하세요, 고객님. 저희 서비스를 이용해주셔서 진심으로 감사드립니다.",
    fileId: rcmFileId,
    buttons: [
      {
        type: "URL",
        name: "버튼명",
        url: "https://www.messent.co.kr"
      }
    ],
    receiverList: [
      {
        phone: "01001231234",
        userKey: "iheart-rcm-1",
      }
    ],
    agencyId: "IHEART",
    agencyKey: "AK.eEt1RjNBZBP1xjC",
    brandId: "BR.m4nxVh6sf4",
    brandKey: "BK.q01CWxkZ2KO2Y9d",
    messageBaseId: "SMwThT00",
    isCopy: "Y",
    expiryOpt: "2",
    header: "1",
    footer: "0800000000"
  }

  console.log('RCM fileId: ', rcmFileId);
  console.log('RCM: ', await sendRcs(rcmPayload));


  await polling();
})();