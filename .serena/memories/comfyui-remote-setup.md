# ComfyUI 원격 생성 웹앱 — 환경 사실

코드만 봐서는 알 수 없는 것들만 적는다. 나머지는 README.md 참고.

## ComfyUI 설치 구조 (헷갈리기 쉬움)

세 경로가 각각 다른 역할을 한다:

- `G:\comfy` — **base directory**. venv(`.venv`), custom_nodes, user, output 이 여기 있다.
  `main.py` 는 **없다**.
- `G:\Comfy-Desktop\ComfyUI-Installs\ComfyUI\ComfyUI\main.py` — 실제 소스
- `G:\Comfy-Desktop\ComfyUI-Shared\models` — 모델 본체. 체크포인트가
  `대형\`, `애니\` 같은 한글 하위폴더로 나뉘어 있다.

실행에 반드시 필요한 인자 (`start-comfy.bat` 에 박아 뒀다):

```
--base-directory G:\comfy
--extra-model-paths-config "%APPDATA%\Comfy Desktop\shared_model_paths.yaml"
--port 8000
```

`--extra-model-paths-config` 를 빼면 서버는 뜨지만 **모델 목록이 전부 비어서**
`Value not in list ckpt_name: ... not in []` 로 모든 실행이 검증 단계에서 죽는다.
경로가 `%APPDATA%\ComfyUI\` 가 아니라 `%APPDATA%\Comfy Desktop\` 인 것에 주의.
실행 인자 원본은 `%APPDATA%\Comfy Desktop\installations.json` 의 `launchArgs`.

## UI 워크플로우 -> API 포맷 변환에서 걸린 것들

`tools/convert_workflow.py` 가 처리하지만, 왜 그렇게 짰는지:

- **`%date:yyyy-MM-dd%` 는 프론트엔드가 치환한다.** API 로 그대로 보내면
  `WinError 267 디렉터리 이름이 올바르지 않습니다` 로 죽는다.
  그래서 `api.php` 가 `filename_prefix` 를 직접 완성한다.
- **`Select Wildcard 🟢 Full Cache`** 는 Impact Pack 캐시 상태에 따라 생겼다 없어지는
  선택지라 검증에 걸린다. 항상 존재하는 첫 항목으로 고정한다.
- **COMBO 스펙이 두 형태로 온다.** 구형은 선택지 배열이 타입 자리에 오고
  (`[["euler", ...], {...}]`), 신형은 `["COMBO", {"options": [...]}]`. 둘 다 위젯으로 세야 한다.
- **`Power Lora Loader (rgthree)`** 는 `INPUT_TYPES` 가 model/clip 만 내놓는 동적 노드라
  object_info 로 위젯 순서를 알 수 없다. 백엔드(`py/power_lora_loader.py`)는
  `lora_N` 키의 `{on, lora, strength, strengthTwo}` dict 만 본다.
- **seed 뒤의 `randomize`/`fixed`** 는 control_after_generate 위젯 값이라 실행값이 아니다. 건너뛴다.
- **`ImpactWildcardEncode` 는 실행 때 `populated_text` 만 쓴다** (`impact_pack.py` 의 `doit`).
  `mode` 와 `wildcard_text` 는 프론트엔드용. 그래서 API 로 보낼 땐 `populated_text` 에 넣고
  `mode` 를 `fixed` 로 둔다.

## 생성 기록 DB (MariaDB `diffusion`)

- **PNG 안에 워크플로우가 통째로 들어 있다.** ComfyUI SaveImage 가 `prompt`(API 포맷) 와
  `workflow`(화면용) 를 tEXt 청크로 심는다. 그래서 웹 폼을 만들기 전에 만든 그림도
  프롬프트·파라미터를 되살릴 수 있다 — `tools/backfill.php` 가 이걸 읽는다.
- **연결되지 않은 노드는 기록에서 빼야 한다.** 워크플로우에는 아무 데도 안 붙은
  `LoraLoader` 3개가 남아 있는데, 실행되지 않으므로 적용된 LoRA 로 세면 거짓 정보가 된다.
  `extract_params` 가 참조 여부를 확인해 걸러낸다.
- **모델 이름이 한 곳에만 있지 않다.** SDXL 계열은 `CheckpointLoaderSimple`,
  Qwen 계열(anima)은 `UNETLoader` 를 쓴다. 둘 다 봐야 한다.
- **PDO 네이티브 프리페어에서는 같은 이름의 자리표시자를 두 번 못 쓴다.**
  `LIKE :q OR LIKE :q` 는 `SQLSTATE[HY093] Invalid parameter number` 로 죽는다.
  `EMULATE_PREPARES => false` 를 쓰는 한 이름을 나눠야 한다.
- 관리자 비밀번호는 `config.local.php` (gitignore). 비어 있으면 관리자 페이지가 열리지 않는다.

## 검증 방법

`/prompt` 에 POST 하기 전에 워크플로우를 검사할 방법이 없다. 실제로 한 장 돌려 보는 게 유일한 검증이다.
정상 결과는 1080x1576 PNG, 40~60초.

## 사용자가 정한 것 (되돌리지 말 것)

- 웹에서 조작 가능한 것은 **포지티브 / 네거티브 / 결과 보기·저장** 뿐.
  샘플러·LoRA·해상도·시드를 폼에 노출하지 않기로 했다.
- **`max_queue` 는 1.** ComfyUI 가 뭐라도 그리는 중이면 원격 요청을 429 로 거절한다.
  사용자가 이 PC 앞에서 직접 작업하는 동안 원격 요청이 GPU 를 가로채는 것을 막으려는 것이므로,
  "여러 장 대기시키면 편하다"는 이유로 늘리면 안 된다.
- **프롬프트 기록은 접속한 기기의 localStorage 에 20개까지.** 프롬프트·네거티브·시각·
  결과 파일 정보·받았는지 여부만 남기고 **이미지는 저장하지 않는다.**
  기록을 지우는 UI 는 일부러 넣지 않았다 — 최종 이미지가 `G:\comfy\output\web\` 에 전부
  남아 있어 이 PC 에서 언제든 볼 수 있으므로, 웹에서 지울 이유가 없다는 판단이다.
- **최종 접속 주소는 `diffusion.example.com`** 이고, Tailscale 로그인한 기기만 닿을 수 있게
  세 겹으로 막았다: 방화벽(`100.64.0.0/10` + `127.0.0.1` 만) / 가상호스트(이 주소는 `www\comfy` 만) /
  기본 사이트에서 Tailscale 대역 거부(`00-default.conf`, 원본은 `.bak`).
  세 번째가 필요한 이유는 `100.x.x.x` 로 직접 치면 ServerName 이 안 맞아 기본 사이트(=`www` 전체)로
  떨어지기 때문이다. 하나라도 빼면 다른 프로젝트 폴더가 노출된다.
- **Windows 자동 생성 방화벽 규칙은 `Set-NetFirewallRule -RemoteAddress` 가 안 먹는다.**
  "사용자가 결정(Query User)" 형식이라 프로그램 경로와 프로토콜 외 조건을 거부한다
  (`HRESULT 0x80070057`). 그래서 그 규칙은 끄고 제한된 규칙을 새로 만든다 — `tools/restrict-firewall.ps1`.
  기본 정책이 세 프로필 모두 BlockInbound 라서 이 방식이 성립한다.
- **이 PC 는 공인 IP 가 랜카드에 직접 붙어 있다** (`ipconfig` 로 확인). 공유기 뒤가 아니다.
  방화벽 제한을 풀면 인터넷에서 `www` 전체에 바로 닿는다.
- **`.bat` 은 CP949(ANSI)로 저장해야 한다.** cmd 는 배치 파일을 시스템 코드페이지로 읽으므로
  UTF-8 로 저장하면 한글 주석까지 깨져 명령으로 잘못 해석되고
  `'...'은(는) 내부 또는 외부 명령이 아닙니다` 가 쏟아진다.
  첫 줄에 `chcp 65001` 을 넣어도 소용없다 — 파일은 이미 읽히기 시작한 뒤다.
  Write 도구는 UTF-8 로 쓰므로 만든 뒤 `[Text.Encoding]::GetEncoding(949)` 로 다시 저장할 것.
- **Windows PowerShell 5.1 은 BOM 없는 UTF-8 을 ANSI 로 읽는다.** 한글이 든 `.ps1` 은
  반드시 UTF-8 BOM 으로 저장할 것. Write 도구는 BOM 을 안 붙이므로 뒤에 따로 붙여야 한다.
- 원격 접근은 **Tailscale**. 공인 IP 노출과 포트포워딩을 피하려는 것이 이유이므로,
  포트포워딩이나 공개 DNS 레코드를 쓰는 방향으로 바꾸면 안 된다.
  사용자 도메인 `example.com` 은 이 건과 무관하다.
