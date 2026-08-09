@echo off
REM 이 파일은 CP949(ANSI)로 저장해야 합니다.
REM UTF-8 로 저장하면 cmd 가 한글을 깨뜨려 명령으로 잘못 해석합니다.
title diffusion.example.com 인증서 발급 (win-acme)

REM Let's Encrypt 인증서를 DNS-01 방식으로 받습니다.
REM
REM 100.x.x.x 는 사설 대역이라 Let's Encrypt 가 웹으로 접속해 확인하는
REM HTTP-01 검증을 쓸 수 없습니다. 그래서 DNS 에 TXT 레코드를 넣어
REM 도메인 소유를 증명하는 DNS-01 방식으로 받습니다.
REM
REM 사용법:
REM   1. https://www.win-acme.com 에서 받은 압축을 풀고, wacs.exe 가 있는
REM      폴더 경로를 아래 WACS 에 적습니다.
REM   2. 이 파일을 더블클릭합니다.
REM   3. 화면에 아래 같은 안내가 뜹니다.
REM
REM        Create a DNS TXT record for _acme-challenge.diffusion.example.com
REM        with the following value: gfj9Xq...(무작위 문자열)
REM
REM   4. hosting.kr DNS 관리에서 레코드를 추가합니다.
REM        유형      TXT
REM        호스트    _acme-challenge.diffusion
REM        값        화면에 나온 문자열 그대로
REM        TTL       180
REM   5. 3분쯤 기다린 뒤 창에서 Enter 를 누릅니다. (TTL 이 180 초라서)
REM   6. 발급이 끝나면 검증용 TXT 레코드는 지워도 됩니다.
REM
REM 90 일마다 이 과정을 다시 해야 합니다. hosting.kr 이 API 를 제공하면
REM 자동 갱신도 가능하지만, 수동 검증에서는 자동 갱신이 되지 않습니다.

set "WACS=C:\laragon\www\win-acme.v2.2.9.1701.x64.pluggable\wacs.exe"
set "OUT=C:\laragon\etc\ssl\diffusion"
set "DOMAIN=diffusion.example.com"
set "EMAIL=you@example.com"

if not exist "%WACS%" (
    echo.
    echo   wacs.exe 를 찾지 못했습니다: %WACS%
    echo   이 파일을 열어 WACS 경로를 실제 위치로 고쳐 주세요.
    echo.
    pause
    exit /b 1
)

"%WACS%" ^
  --source manual ^
  --host %DOMAIN% ^
  --validationmode dns-01 ^
  --validation manual ^
  --store pemfiles ^
  --pemfilespath "%OUT%" ^
  --accepttos ^
  --emailaddress %EMAIL%

echo.
echo 발급이 끝났다면 %OUT% 에 pem 파일이 생겼는지 확인하세요.
echo 그 다음 Apache 설정의 인증서 경로를 바꾸고 Reload 하면 됩니다.
pause
