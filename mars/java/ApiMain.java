import java.io.File;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.HashMap;
import java.util.Map;
import org.apache.hc.client5.http.classic.methods.HttpGet;
import org.apache.hc.client5.http.classic.methods.HttpPost;
import org.apache.hc.client5.http.classic.methods.HttpUriRequestBase;
import org.apache.hc.client5.http.entity.mime.HttpMultipartMode;
import org.apache.hc.client5.http.entity.mime.MultipartEntityBuilder;
import org.apache.hc.client5.http.impl.classic.CloseableHttpClient;
import org.apache.hc.client5.http.impl.classic.HttpClients;
import org.apache.hc.core5.http.ContentType;
import org.apache.hc.core5.http.HttpEntity;
import org.apache.hc.core5.http.HttpHeaders;
import org.apache.hc.core5.http.ParseException;
import org.apache.hc.core5.http.io.HttpClientResponseHandler;
import org.apache.hc.core5.http.io.entity.EntityUtils;
import org.apache.hc.core5.http.io.entity.StringEntity;

public class ApiMain {

  private final String clientId;
  private final String password;
  private final String baseUrl;
  private String token;

  public ApiMain(String clientId, String password, String baseUrl) {
    this.clientId = clientId;
    this.password = password;
    this.baseUrl = baseUrl;
  }

  public boolean auth() throws IOException {
    String url = baseUrl + "/api/v1/auth";
    String json = "{\"clientId\":\"" + clientId + "\",\"password\":\"" + password + "\"}";

    HttpPost post = new HttpPost(url);
    post.setEntity(new StringEntity(json, ContentType.APPLICATION_JSON));
    post.setHeader(HttpHeaders.CONTENT_TYPE, "application/json; charset=utf-8");

    try (CloseableHttpClient client = HttpClients.createDefault()) {
      HttpClientResponseHandler<Boolean> handler = response -> {
        try {
          String responseString = EntityUtils.toString(response.getEntity(),
              StandardCharsets.UTF_8);
          if (responseString.contains("\"code\":\"100\"")) {
            int start = responseString.indexOf("\"accessToken\":\"") + 15;
            int end = responseString.indexOf("\"", start);
            token = responseString.substring(start, end);
            return true;
          }
        } catch (ParseException e) {
          throw new IOException("파싱 오류", e);
        }
        return false;
      };

      return client.execute(post, handler);
    }
  }

  public String request(String method, String path, String jsonPayload, Map<String, String> headers)
      throws IOException {
    String url = baseUrl + path;
    HttpUriRequestBase req = method.equalsIgnoreCase("POST") ? new HttpPost(url) : new HttpGet(url);

    // 기본 Authorization 헤더 추가
    req.setHeader(HttpHeaders.AUTHORIZATION, "Bearer " + token);
    req.setHeader(HttpHeaders.CONTENT_TYPE, "application/json; charset=utf-8");

    // 추가 헤더들 설정
    if (headers != null) {
      for (Map.Entry<String, String> entry : headers.entrySet()) {
        req.setHeader(entry.getKey(), entry.getValue());
      }
    }

    if (req instanceof HttpPost && jsonPayload != null) {
      req.setEntity(new StringEntity(jsonPayload, ContentType.APPLICATION_JSON));
    }

    try (CloseableHttpClient client = HttpClients.createDefault()) {
      HttpClientResponseHandler<String> handler = response -> {
        try {
          return EntityUtils.toString(response.getEntity(), StandardCharsets.UTF_8);
        } catch (ParseException e) {
          throw new IOException("응답 파싱 오류", e);
        }
      };

      String respStr = client.execute(req, handler);

      if (checkToken(respStr)) {
        System.out.println("⚠️ 토큰이 없거나 만료로 재인증 시도중...\n\n");
        if (!auth()) {
          throw new IOException("재인증 실패");
        }

        req.setHeader(HttpHeaders.AUTHORIZATION, "Bearer " + token);
        respStr = client.execute(req, handler);
      }

      return respStr;
    }
  }

  private boolean checkToken(String responseString) {
    if (responseString == null) {
      return true;
    }

    return responseString.contains("\"code\":\"401\"") || responseString.contains(
        "\"code\":\"498\"");
  }

  public String uploadFile(String filePath, String fileType) throws IOException {
    String url = baseUrl + "/api/v1/file";
    Path basePath = Paths.get(System.getProperty("user.dir")).resolve("mars");

    File file = basePath.resolve(filePath).toAbsolutePath().toFile();
    if (!file.exists()) {
      throw new IOException("파일 없음: " + filePath);
    }

    HttpEntity entity = MultipartEntityBuilder.create()
        .setMode(HttpMultipartMode.STRICT)
        .addBinaryBody("filePart", file)
        .addTextBody("fileType", fileType)
        .build();

    HttpPost post = new HttpPost(url);
    post.setHeader(HttpHeaders.AUTHORIZATION, "Bearer " + token);
    post.setEntity(entity);

    try (CloseableHttpClient client = HttpClients.createDefault()) {
      HttpClientResponseHandler<String> handler = response -> {
        try {
          String respStr = EntityUtils.toString(response.getEntity(), StandardCharsets.UTF_8);
          if (respStr.contains("\"code\":\"100\"")) {
            int start = respStr.indexOf("\"fileId\":\"") + 10;
            int end = respStr.indexOf("\"", start);
            return respStr.substring(start, end);
          }
          return null;
        } catch (ParseException e) {
          throw new IOException("응답 파싱 오류", e);
        }
      };

      String respStr = client.execute(post, handler);

      if (checkToken(respStr)) {
        System.out.println("⚠️ 토큰이 없거나 만료로 재인증 시도중...\n\n");
        if (!auth()) {
          throw new IOException("재인증 실패");
        }

        post.setHeader(HttpHeaders.AUTHORIZATION, "Bearer " + token);
        respStr = client.execute(post, handler);
      }

      return respStr;
    }
  }

  public String sendSms(String payload) throws IOException {
    return request("POST", "/api/v1/send/sms", payload, null);
  }

  public String sendMms(String payload) throws IOException {
    return request("POST", "/api/v1/send/mms", payload, null);
  }

  public String sendAlt(String payload) throws IOException {
    return request("POST", "/api/v1/send/alt", payload, null);
  }

  public String sendRcs(String payload) throws IOException {
    return request("POST", "/api/v1/send/rcs", payload, null);
  }

  public void polling() throws IOException, InterruptedException {
    int retries = 5;
    int interval = 5000;
    String rsltKey = null;

    Map<String, String> headers = new HashMap<>();
    headers.put(HttpHeaders.CONTENT_TYPE, "application/json; charset=utf-8");

    for (int i = 0; i < retries; i++) {
      String response = request("GET", "/api/v1/report", null, headers);
      System.out.printf("폴링 가능 여부 확인 중... (시도: %d/%d)\n", i + 1, retries);

      if (response.contains("\"data\"")) {
        break;
      }

      if (i + 1 < retries) {
        Thread.sleep(interval);
      } else {
        System.out.println("최대 시도 횟수 도달. 폴링 실패.");
        return;
      }
    }

    while (true) {
      String path = "/api/v1/report";
      if (rsltKey != null) {
        path += "/" + rsltKey;
      }

      String response = request("GET", path, null, headers);
      System.out.println(response);

      int start = response.indexOf("\"rsltKey\":\"");
      if (start == -1) {
        break;
      }
      start += 11;
      int end = response.indexOf("\"", start);
      rsltKey = response.substring(start, end);
    }
  }

  public static void main(String[] args) throws IOException, InterruptedException {
    String clientId = "rest_real_03";
    String password = "dkdlgkxm1!";
    String baseUrl = "http://dev-poc-gw1-vnet.i-heart.kr:8000";

    ApiMain apiMain = new ApiMain(clientId, password, baseUrl);

    String smsPayload =
        "{"
        + "\"callback\": \"16442105\","
        + "\"message\": \"안녕하세요. #{회사명} 소속 #{이름}입니다.\","
        + "\"receiverList\": ["
        + "  {"
        + "    \"phone\": \"01001231234\","
        + "    \"userKey\": \"iheart-sms-1\","
        + "    \"customFields\": {"
        + "      \"이름\": \"김바른\","
        + "      \"회사명\": \"아이하트\""
        + "    }"
        + "  }"
        + "]"
        + "}";

    System.out.println("SMS: " + apiMain.sendSms(smsPayload));

    String lmsPayload =
        "{"
        + "\"callback\": \"16442105\","
        + "\"subject\": \"안내드립니다\","
        + "\"message\": \"LMS 메시지입니다. #{이름} 고객님 확인 부탁드립니다.\","
        + "\"receiverList\": ["
        + "  {"
        + "    \"phone\": \"01001231234\","
        + "    \"userKey\": \"iheart-lms-1\","
        + "    \"customFields\": {"
        + "      \"이름\": \"김찬란\""
        + "    }"
        + "  }"
        + "]"
        + "}";

    apiMain.sendMms(lmsPayload);

    String mmsFileId = apiMain.uploadFile("assets/sample.jpg", "MMS");
    String mmsPayload =
        "{"
        + "\"callback\": \"16442105\","
        + "\"subject\": \"알립니다\","
        + "\"message\": \"#{이름}님의 이메일 주소가 #{이메일}인지 확인해 주세요.\","
        + "\"receiverList\": ["
        + "  {"
        + "    \"phone\": \"0100000000\","
        + "    \"userKey\": \"11111\","
        + "    \"customFields\": {"
        + "      \"이름\": \"김바른\","
        + "      \"이메일\": \"iheart@i-heart.kr\""
        + "    }"
        + "  }"
        + "],"
        + "\"fileIdList\": ["
        + "  \"" + mmsFileId + "\""
        + "]"
        + "}";

    System.out.println("File ID: " + mmsFileId);
    System.out.println("MMS: " + apiMain.sendMms(mmsPayload));

    String altPayload =
        "{"
        + "\"callback\": \"16442105\","
        + "\"message\": \"안녕하세요, (주)아이하트입니다.\\n해당 템플릿은 테스트건으로 승인 부탁드립니다.\\n감사합니다.\","
        + "\"receiverList\": ["
        + "  {"
        + "    \"phone\": \"01001231234\","
        + "    \"userKey\": \"iheart-alt-1\","
        + "    \"customFields\": {"
        + "      \"이름\": \"김바른\","
        + "      \"회사\": \"아이하트\""
        + "    }"
        + "  }"
        + "],"
        + "\"title\": \"강조형 문구\","
        + "\"senderKey\": \"fa14aa22ac69f174651d48d201111af25aac66e7\","
        + "\"templateCode\": \"TEMPLATEJBJt20241118103614\","
        + "\"type\": \"ALT\","
        + "\"buttons\": ["
        + "  {"
        + "    \"name\": \"버튼명\","
        + "    \"type\": \"WL\","
        + "    \"linkMo\": \"https://www.messent.co.kr\","
        + "    \"linkPc\": \"https://www.messent.co.kr\""
        + "  }"
        + "],"
        + "\"fallback\": {"
        + "  \"msgType\": \"SMS\","
        + "  \"message\": \"[대체문자] 안녕하세요 #{회사} #{이름}입니다.\""
        + "}"
        + "}";

    System.out.println("ALT: " + apiMain.sendAlt(altPayload));

    String aliFileId = apiMain.uploadFile("assets/sample.jpg", "MMS");
    String aliPayload =
        "{"
        + "\"callback\": \"16442105\","
        + "\"message\": \"등록테스트입니다.\\n\\n[아이하트 영업팀]\\n\\n#{이름}고객님의 적립금 소멸 예정 안내드립니다.\","
        + "\"receiverList\": ["
        + "  {"
        + "    \"phone\": \"01001231234\","
        + "    \"userKey\": \"iheart-ali-1\","
        + "    \"customFields\": {"
        + "      \"이름\": \"김바른\""
        + "    }"
        + "  }"
        + "],"
        + "\"senderKey\": \"00123c6160d2a054d336905ede205fd9b1524757\","
        + "\"templateCode\": \"TEMPLATEyfcl20240925105620\","
        + "\"type\": \"ALI\","
        + "\"fallback\": {"
        + "  \"msgType\": \"MMS\","
        + "  \"subject\": \"대체문자\","
        + "  \"message\": \"[테스트] 알림톡 기본형 테스트입니다.\","
        + "  \"fileIdList\": [\"" + aliFileId + "\"]"
        + "}"
        + "}";

    System.out.println("File ID: " + aliFileId);
    System.out.println("ALI: " + apiMain.sendAlt(aliPayload));

    String rcsPayload =
        "{"
        + "\"callback\": \"16442105\","
        + "\"subject\": \"메시지 제목\","
        + "\"message\": \"안녕하세요. #{주소}에 사는 #{대상자} 입니다.\","
        + "\"buttons\": ["
        + "  {"
        + "    \"type\": \"URL\","
        + "    \"name\": \"버튼명\","
        + "    \"url\": \"https://www.messent.co.kr\""
        + "  }"
        + "],"
        + "\"receiverList\": ["
        + "  {"
        + "    \"phone\": \"01001231234\","
        + "    \"userKey\": \"iheart-rcs-1\","
        + "    \"customFields\": {"
        + "      \"대상자\": \"정의진\","
        + "      \"주소\": \"서울특별시 금천구\""
        + "    }"
        + "  }"
        + "],"
        + "\"agencyId\": \"IHEART\","
        + "\"agencyKey\": \"AK.eEt1RjNBZBP1xjC\","
        + "\"brandId\": \"BR.m4nxVh6sf4\","
        + "\"brandKey\": \"BK.q01CWxkZ2KO2Y9d\","
        + "\"messageBaseId\": \"SS000000\","
        + "\"isCopy\": \"Y\","
        + "\"expiryOpt\": \"2\","
        + "\"header\": \"1\","
        + "\"footer\": \"0800000000\","
        + "\"fallback\": {"
        + "  \"msgType\": \"SMS\","
        + "  \"message\": \"안녕하세요. #{주소}에 사는 #{대상자} 입니다.\""
        + "}"
        + "}";

    System.out.println("RCS: " + apiMain.sendRcs(rcsPayload));

    String rclPayload =
        "{"
        + "\"callback\": \"16442105\","
        + "\"subject\": \"메시지 제목\","
        + "\"message\": \"안녕하세요, 고객님. 저희 서비스를 이용해주셔서 진심으로 감사드립니다.\","
        + "\"buttons\": ["
        + "  {"
        + "    \"type\": \"URL\","
        + "    \"name\": \"버튼명\","
        + "    \"url\": \"https://www.messent.co.kr\""
        + "  }"
        + "],"
        + "\"receiverList\": ["
        + "  {"
        + "    \"phone\": \"01001231234\","
        + "    \"userKey\": \"iheart-rcl-1\""
        + "  }"
        + "],"
        + "\"agencyId\": \"IHEART\","
        + "\"agencyKey\": \"AK.eEt1RjNBZBP1xjC\","
        + "\"brandId\": \"BR.m4nxVh6sf4\","
        + "\"brandKey\": \"BK.q01CWxkZ2KO2Y9d\","
        + "\"messageBaseId\": \"SL000000\","
        + "\"isCopy\": \"Y\","
        + "\"expiryOpt\": \"2\","
        + "\"header\": \"1\","
        + "\"footer\": \"0800000000\","
        + "\"fallback\": {"
        + "  \"msgType\": \"SMS\","
        + "  \"message\": \"안녕하세요, 고객님. 대체문자 발송드립니다.\""
        + "}"
        + "}";

    System.out.println("RCL: " + apiMain.sendRcs(rclPayload));

    String rcmFileId = apiMain.uploadFile("assets/sample.jpg", "RCS");
    String rcmPayload =
        "{"
        + "\"callback\": \"16442105\","
        + "\"subject\": \"안내사항\","
        + "\"message\": \"안녕하세요, 고객님. 저희 서비스를 이용해주셔서 진심으로 감사드립니다.\","
        + "\"fileId\": \"" + rcmFileId + "\","
        + "\"buttons\": ["
        + "  {"
        + "    \"type\": \"URL\","
        + "    \"name\": \"버튼명\","
        + "    \"url\": \"https://www.messent.co.kr\""
        + "  }"
        + "],"
        + "\"receiverList\": ["
        + "  {"
        + "    \"phone\": \"01001231234\","
        + "    \"userKey\": \"iheart-rcm-1\""
        + "  }"
        + "],"
        + "\"agencyId\": \"IHEART\","
        + "\"agencyKey\": \"AK.eEt1RjNBZBP1xjC\","
        + "\"brandId\": \"BR.m4nxVh6sf4\","
        + "\"brandKey\": \"BK.q01CWxkZ2KO2Y9d\","
        + "\"messageBaseId\": \"SMwThT00\","
        + "\"isCopy\": \"Y\","
        + "\"expiryOpt\": \"2\","
        + "\"header\": \"1\","
        + "\"footer\": \"0800000000\""
        + "}";

    System.out.println("File ID: " + rcmFileId);
    System.out.println("RCM: " + apiMain.sendRcs(rcmPayload));

    apiMain.polling();
  }
}