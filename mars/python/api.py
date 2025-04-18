import time
import requests


class MarsClient:
  def __init__(self, client_id, password, base_url):
    self.client_id = client_id
    self.password = password
    self.base_url = base_url
    self.token = None

  def auth(self):
    url = f'{self.base_url}/api/v1/auth'
    payload = {
      'clientId': self.client_id,
      'password': self.password
    }
    headers = {'Content-Type': 'application/json; charset=utf-8'}
    resp = requests.post(url, json=payload, headers=headers).json()
    if resp.get('code') == '100':
      self.token = resp['accessToken']
      return True
    return False

  def _check_token(self, resp):
    code = resp.get('code')
    return code in ['401', '498']

  def _request(self, method, path, **kwargs):
    headers = kwargs.pop('headers', {})
    headers['Authorization'] = f'Bearer {self.token}'
    url = f'{self.base_url}{path}'
    resp = requests.request(method, url, headers=headers, **kwargs)
    data = resp.json()
    if self._check_token(data):
      print("⚠️ 토큰이 없거나 만료로 재인증 시도중...\n\n")
      self.auth()
      headers['Authorization'] = f'Bearer {self.token}'
      resp = requests.request(method, url, headers=headers, **kwargs)
      data = resp.json()
    return data

  def send_sms(self, payload):
    return self._request('POST', '/api/v1/send/sms', json=payload,
                         headers={
                           'Content-Type': 'application/json; charset=utf-8'})

  def send_mms(self, payload):
    return self._request('POST', '/api/v1/send/mms', json=payload,
                         headers={
                           'Content-Type': 'application/json; charset=utf-8'})

  def send_alt(self, payload):
    return self._request('POST', '/api/v1/send/alt', json=payload,
                         headers={
                           'Content-Type': 'application/json; charset=utf-8'})

  def send_rcs(self, payload):
    return self._request('POST', '/api/v1/send/rcs', json=payload,
                         headers={
                           'Content-Type': 'application/json; charset=utf-8'})

  def upload_file(self, file_path, file_type='MMS'):
    url = f'{self.base_url}/api/v1/file'
    headers = {'Authorization': f'Bearer {self.token}'}
    files = {
      'filePart': open(file_path, 'rb')
    }
    data = {'fileType': file_type}
    resp = requests.post(url, headers=headers, files=files, data=data)
    resp_data = resp.json()
    if resp_data.get('code') == '100':
      return resp_data['data']['fileId']
    return None

  def get_report(self):
    retries = 5
    interval = 5
    rslt_key = None
  
    for cnt in range(retries):
      url = f'/api/v1/report'

      data = self._request('GET', url, headers={'Content-Type': 'application/json; charset=utf-8'})
      print(f"폴링 가능 여부 확인 중... (시도: {cnt + 1}/{retries})")

      if 'data' in data:
        break
      
      if cnt + 1 < retries:
        time.sleep(interval)
      else:
        print("최대 시도 횟수 도달. 폴링 실패.")
        return

    while True:
      url = f'/api/v1/report'
      if rslt_key:
        url += f'/{rslt_key}'
      data = self._request('GET', url, headers={'Content-Type': 'application/json; charset=utf-8'})
      print(data);

      rslt_key = data.get('data', {}).get('rsltKey')

      if not rslt_key:
        break


def main():
  client = MarsClient(client_id='rest_real_03', password='dkdlgkxm1!',
                      base_url='http://dev-poc-gw1-vnet.i-heart.kr:8000')

  sms_payload = {
    'callback': '16442105',
    'message': '안녕하세요. #{회사명} 소속 #{이름}입니다.',
    'receiverList': [
      {'phone': '01000000000', 'userKey': 'user-key-1', 'customFields': {'이름': '홍길동', '회사명': '아이하트'}}
    ]
  }
  print('SMS:', client.send_sms(sms_payload))

  lms_payload = {
    'callback': '16442105',
    'subject': '제목입니다',
    'message': 'LMS 테스트 메시지입니다.',
    'receiverList': [
      {'phone': '01000000000', 'userKey': 'user-key-2'}
    ]
  }
  print('LMS:', client.send_mms(lms_payload))

  file_id = client.upload_file('../assets/sample.jpg', file_type='MMS')
  mms_payload = {
    'callback': '16442105',
    'subject': '제목입니다',
    'message': 'MMS 테스트 메시지입니다.',
    'receiverList': [
      {'phone': '01000000000', 'userKey': 'user-key-2'}
    ],
    'fileIdList': [file_id] if file_id else []
  }

  print('MMS:', client.send_mms(mms_payload))

  alt_payload = {
    'callback': '16442105',
    'message': '알림톡 메시지입니다.',
    'receiverList': [{'phone': '01000000000', 'userKey': 'user-key-3'}],
    'senderKey': 'senderKey',
    'templateCode': 'templateCode',
    'type': 'ALT'
  }
  print('ALT:', client.send_alt(alt_payload))

  ali_payload = alt_payload.copy()
  ali_payload['type'] = 'ALI'
  print('ALI:', client.send_alt(ali_payload))

  rcs_payload = {
    'callback': '16442105',
    'subject': '메시지 제목',
    'message': '안녕하세요. #{주소}에 사는 #{대상자} 입니다.',
    'buttons': [
      {
        'type': 'URL',
        'name': '버튼명',
        'url': 'https://www.messent.co.kr'
      }
    ],
    'receiverList': [
      {
        'phone': '01001231234',
        'userKey': 'iheart-rcs-1',
        'customFields': {
          '대상자': '정의진',
          '주소': '서울특별시 금천구'
        }
      }
    ],
    'agencyId': 'IHEART',
    'agencyKey': 'AK.eEt1RjNBZBP1xjC',
    'brandId': 'BR.m4nxVh6sf4',
    'brandKey': 'BK.q01CWxkZ2KO2Y9d',
    'messageBaseId': 'SS000000',
    'isCopy': 'Y',
    'expiryOpt': '2',
    'header': '1',
    'footer': '0800000000',
    'fallback': {
      'msgType': 'SMS',
      'message': '안녕하세요. #{주소}에 사는 #{대상자} 입니다.'
    }
  }

  print('RCS:', client.send_rcs(rcs_payload))

  rcl_payload = {
    'callback': '16442105',
    'subject': '메시지 제목',
    'message': '안녕하세요, 고객님. 저희 서비스를 이용해주셔서 진심으로 감사드립니다.',
    'buttons': [
      {
        'type': 'URL',
        'name': '버튼명',
        'url': 'https://www.messent.co.kr'
      }
    ],
    'receiverList': [
      {
        'phone': '01001231234',
        'userKey': 'iheart-rcl-1'
      }
    ],
    'agencyId': 'IHEART',
    'agencyKey': 'AK.eEt1RjNBZBP1xjC',
    'brandId': 'BR.m4nxVh6sf4',
    'brandKey': 'BK.q01CWxkZ2KO2Y9d',
    'messageBaseId': 'SL000000',
    'isCopy': 'Y',
    'expiryOpt': '2',
    'header': '1',
    'footer': '0800000000',
    'fallback': {
      'msgType': 'SMS',
      'message': '안녕하세요, 고객님. 대체문자 발송드립니다.'
    }
  }
  print('RCL:', client.send_rcs(rcl_payload))

  rcm_file_id = client.upload_file('../assets/sample.jpg', file_type='RCS')
  rcm_payload = rcl_payload.copy()
  rcm_payload['fileId'] = rcm_file_id
  rcm_payload['messageBaseId'] = 'SMwThT00'

  print('RCM:', client.send_rcs(rcm_payload))

  client.get_report()

if __name__ == '__main__':
  main()
