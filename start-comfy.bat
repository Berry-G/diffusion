@echo off
REM 이 파일은 CP949(ANSI)로 저장해야 합니다.
REM UTF-8 로 저장하면 cmd 가 한글을 깨뜨려 명령으로 잘못 해석합니다.
title ComfyUI (원격 생성용 백엔드)

REM Comfy Desktop 앱을 켜지 않고 생성 엔진만 띄웁니다.
REM Desktop 앱을 켜도 같은 8000 포트를 쓰므로 둘 중 하나만 실행하세요.
REM
REM --listen 127.0.0.1 은 일부러 붙여 둔 것입니다.
REM 이렇게 해야 원격에서 ComfyUI 화면에 직접 붙어 워크플로우를 열거나
REM 고칠 수 없고, 웹 폼(api.php)을 통해서만 생성할 수 있습니다.

set "PY=G:\comfy\.venv\Scripts\python.exe"
set "MAIN=G:\Comfy-Desktop\ComfyUI-Installs\ComfyUI\ComfyUI\main.py"
set "MODELS=%APPDATA%\Comfy Desktop\shared_model_paths.yaml"

"%PY%" "%MAIN%" ^
  --base-directory "G:\comfy" ^
  --extra-model-paths-config "%MODELS%" ^
  --listen 127.0.0.1 ^
  --port 8000 ^
  --enable-manager

echo.
echo ComfyUI 가 종료되었습니다.
pause
