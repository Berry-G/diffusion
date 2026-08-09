# 에셋 원격 생성기

집 PC의 ComfyUI(RTX 3080)를 밖에서 쓰기 위한 웹 폼입니다.
쓰는 사람은 **프롬프트 / 네거티브 / 결과 보기·저장** 세 가지만 할 수 있고,
샘플러·LoRA·해상도 같은 나머지 설정은 `workflow.json` 워크플로우에 고정돼 있습니다.

## 켜는 순서

1. **ComfyUI 켜기** — `start-comfy.bat` 을 더블클릭
   (Comfy Desktop 앱을 켜도 됩니다. 같은 8000 포트를 쓰므로 **둘 중 하나만**)
2. **Laragon 켜기** — Apache 가 떠 있어야 웹 폼이 열립니다
3. 브라우저에서 접속
   - 이 PC에서: <http://localhost/comfy/>
   - 밖에서: `http://diffusion.example.com`

## 접속 구조

목표는 **`diffusion.example.com` 로 열리되, 아무나 들어오지는 못하는 것**입니다.
Tailscale 에 로그인한 기기만 닿을 수 있게 세 겹으로 막아 뒀습니다.

| 겹 | 무엇을 막나 |
|---|---|
| 방화벽 | Apache 인바운드를 `127.0.0.1` 과 `100.64.0.0/10`(Tailscale 대역) 에서만 받습니다 |
| 가상호스트 | `diffusion.example.com` 은 `www\comfy` 만 내보냅니다. 다른 프로젝트 폴더는 404 |
| 기본 사이트 차단 | Tailscale 대역에서 `100.x.x.x` 로 직접 쳐도 `www` 목록을 볼 수 없습니다 |

`.htaccess` 가 `workflow_api.json`, `start-comfy.bat`, `tools/` 처럼
브라우저가 볼 이유가 없는 것들을 추가로 막습니다.

### 남은 설정 (Tailscale 설치 후)

1. <https://tailscale.com/download/windows> 설치 → 로그인
2. `tailscale ip -4` 로 이 PC 의 `100.x.x.x` 확인
3. DNS 에 `diffusion` **A 레코드 → 그 IP** 추가
4. 쓸 사람은 관리 화면(Users → Invite)에서 초대하고, ACL 로 이 PC 의 80/443 만 열기

```json
{"action": "accept", "src": ["쓸사람@example.com"], "dst": ["tag:comfy:80,443"]}
```

초대받은 사람도 **자기 기기에 Tailscale 을 설치하고 로그인해야** 합니다.
주소만 알려 준다고 열리지 않습니다.

### https

지금은 Laragon 의 자체 서명 인증서라 `https://` 로 열면 브라우저가 경고를 냅니다.
`100.x.x.x` 는 사설 대역이라 Let's Encrypt 의 HTTP-01 검증을 쓸 수 없으므로,
**DNS-01** 방식으로 인증서를 받아
`C:\laragon\etc\apache2\sites-enabled\diffusion.example.com.conf` 의
`SSLCertificateFile` / `SSLCertificateKeyFile` 두 줄만 바꾸면 됩니다.

### 방화벽 제한 되돌리기

```
powershell -ExecutionPolicy Bypass -File tools\restrict-firewall.ps1 -Revert
```

제한을 걸면 LAN(192.168.x.x) 과 인터넷에서 **Laragon 의 모든 사이트**가 막힙니다.
comfy 뿐 아니라 같은 Laragon 아래에 있는 다른 프로젝트까지 전부입니다. `localhost` 는 그대로 됩니다.

**Apache 를 다시 켤 때 Windows 방화벽 팝업이 뜨면 [취소] 를 누르세요.**
[액세스 허용] 을 누르면 모든 주소를 허용하는 규칙이 다시 생겨 제한이 무의미해집니다.

## 왜 ComfyUI 를 127.0.0.1 로 묶어 뒀나

`start-comfy.bat` 은 일부러 `--listen 127.0.0.1` 로 띄웁니다.
이러면 원격에서 ComfyUI 화면 자체에는 접근할 수 없고,
`api.php` 가 허용한 세 가지 — 생성 요청 / 진행 확인 / 결과 내려받기 — 만 가능합니다.
워크플로우를 열거나 고치는 건 이 PC 앞에서만 됩니다.

## 파일

| 파일 | 하는 일 |
|---|---|
| `index.php` | 입력 폼 화면 |
| `assets/app.js` | 요청 보내고 1.5초마다 진행 확인 |
| `api.php` | ComfyUI 프록시. 브라우저는 여기하고만 대화합니다 |
| `config.php` | 노드 번호, 저장 경로, 큐 제한 |
| `workflow_api.json` | 워크플로우를 API 포맷으로 바꿔 둔 것 |
| `tools/convert_workflow.py` | 위 파일을 다시 만드는 변환기 |
| `admin.php` | 관리자 페이지 — 만든 이미지와 파라미터 보기 |
| `lib/db.php` | 생성 기록 저장 (MariaDB) |
| `lib/prompt.php` | 프롬프트를 태그로 쪼개고, 워크플로우에서 파라미터를 뽑아냄 |
| `lib/thumb.php` | 목록용 썸네일 생성·캐시 |
| `tools/backfill.php` | 이미 만든 이미지를 PNG 메타데이터로 DB 에 불러오기 |
| `tools/restrict-firewall.ps1` | Apache 를 로컬 + Tailscale 에서만 받도록 제한 (`-Revert` 로 원복) |
| `tools/get-cert.bat` | Let's Encrypt 인증서 발급 (90일마다) |
| `config.local.php` | 관리자 비밀번호 등 이 기기에만 두는 설정 (저장소에 안 올라감) |
| `.htaccess` | 브라우저가 볼 이유 없는 파일 차단 |
| `start-comfy.bat` | ComfyUI 를 생성 엔진으로만 띄우기 |

Apache 쪽 설정은 프로젝트 밖에 있습니다:

| 파일 | 하는 일 |
|---|---|
| `C:\laragon\etc\apache2\sites-enabled\diffusion.example.com.conf` | 이 주소가 `www\comfy` 만 내보내게 |
| `C:\laragon\etc\apache2\sites-enabled\00-default.conf` | 기본 사이트를 Tailscale 대역에서 차단 (원본은 `.bak`) |

생성된 이미지는 `G:\comfy\output\web\날짜\시각_시드_00001_.png` 에 쌓입니다.
ComfyUI 에서 직접 만든 것(`img\...`)과 폴더가 나뉘어 섞이지 않습니다.
이 PC 에서는 이 폴더를 열어 만든 것을 전부 볼 수 있습니다 — 웹에서 지우는 기능은 없습니다.

내려받을 때는 날짜가 이름 앞에 붙습니다: `2026-08-09_134356_814520644439968.png`.
폴더에 있던 날짜를 파일 이름으로 옮겨, 받아 놓고 나서도 언제 만든 것인지 알 수 있게 한 것입니다.

## 관리자 페이지

`/admin.php` 에서 만든 이미지를 전부 훑어볼 수 있습니다.

- **썸네일 그리드** — 원본이 한 장에 1.5MB 라 그대로 띄우면 감당이 안 되므로,
  긴 변 360px JPEG 을 만들어 `data/thumbs` 에 캐시합니다 (1/100 크기)
- **카드를 누르면** 그 이미지와 함께 **그때 쓴 파라미터가 전부** 나옵니다 —
  모델, 샘플러, 스텝, CFG, 해상도, Hires 설정, LoRA와 강도, 노드별 시드
- **태그** — 프롬프트를 태그로 쪼개 저장합니다. 태그를 누르면 그 태그가 들어간 것만 모아 봅니다
- **검색·필터** — 프롬프트 본문 검색, 상태별(완성/생성중/실패) 필터

### 비밀번호

`config.local.php.example` 을 `config.local.php` 로 복사하고 `admin_password` 를 채우세요.
비어 있으면 관리자 페이지가 열리지 않습니다. 이 파일은 `.gitignore` 에 있어 저장소에 올라가지 않습니다.

Tailscale 안이라 해도 초대한 사람은 들어올 수 있으므로 한 겹 더 막는 것입니다.

## 생성 기록 (MariaDB)

DB 이름은 `diffusion` 입니다.

| 테이블 | 내용 |
|---|---|
| `generations` | 생성 1건. 프롬프트, 파라미터, 결과 파일, 실행한 워크플로우 전체 |
| `tags` | 프롬프트에서 쪼갠 태그 |
| `generation_tags` | 이미지와 태그의 연결 (포지티브/네거티브, 가중치) |

**DB 가 꺼져 있어도 이미지 생성은 그대로 됩니다.** 기록만 빠집니다 —
MariaDB 때문에 그림을 못 만드는 일이 없도록 일부러 그렇게 해 두었습니다.

### 이미 만든 이미지 불러오기

ComfyUI 는 PNG 안에 그때 실행한 워크플로우를 통째로 심어 둡니다.
그래서 이 웹 폼을 만들기 전에 만든 그림도 프롬프트와 파라미터를 되살릴 수 있습니다.

```
php tools\backfill.php          출력 폴더 전체
php tools\backfill.php web      web 폴더만
php tools\backfill.php --dry    무엇이 잡히는지 확인만
```

여러 번 돌려도 안전합니다. 이미 있는 것은 파라미터만 다시 읽어 갱신합니다.

## 최근 프롬프트

접속한 기기의 브라우저(localStorage)에 **최근 20개**까지 남습니다.
남는 것은 프롬프트·네거티브·시각·결과 파일 이름·받았는지 여부뿐이고,
**이미지 자체는 저장하지 않습니다.**

- 항목을 누르면 그 프롬프트가 입력칸에 다시 채워집니다
- 완성된 것은 오른쪽 `⤓` 로 다시 받을 수 있고, 한 번 받으면 `✓` 와 `받음` 으로 바뀝니다
- 기기마다 따로 쌓입니다 (폰에서 만든 기록은 PC 에 안 보입니다)

브라우저가 저장 완료를 알려주지는 않으므로, `받음` 은 정확히는 *받기를 눌렀다* 는 표시입니다.

## 워크플로우를 고쳤다면

ComfyUI 화면에서 `workflow.json` 을 수정한 뒤:

```
G:\comfy\.venv\Scripts\python.exe tools\convert_workflow.py
```

ComfyUI 가 켜져 있어야 합니다 — 실제 노드 스펙을 `/object_info` 에서 받아
위젯 순서를 맞추기 때문입니다.

**노드 번호가 바뀌었다면** `config.php` 의 `nodes` 매핑도 함께 고쳐야 합니다.
현재 매핑:

| 역할 | 노드 | 종류 |
|---|---|---|
| 포지티브 | 116 | ImpactWildcardEncode |
| 네거티브 | 107 | CLIPTextEncode |
| 1차 샘플러 | 117 | KSampler |
| 결과 저장 | 92 | SaveImage |

## 알아 둘 것

- **프롬프트에 `<lora:이름:0.9>` 를 그대로 쓸 수 있습니다.** webui 문법을
  ImpactWildcardEncode 가 해석합니다. 와일드카드 `__이름__` 도 됩니다.
- **이 PC 는 공인 IP 가 랜카드에 직접 붙어 있습니다** (공유기 뒤가 아님).
  방화벽을 제한하기 전에는 인터넷에서 `www` 폴더 전체에 닿을 수 있는 상태였습니다.
  `tools\restrict-firewall.ps1` 이 그것을 막습니다 — 되돌리면 다시 열립니다.
- **그리는 중에는 새 요청을 아예 받지 않습니다.** 원격에서 생성 버튼을 눌러도
  `지금 다른 그림을 그리는 중입니다` 로 거절됩니다. 이 PC 앞에서 ComfyUI 로
  직접 작업하는 동안 원격 요청이 GPU 를 가로채지 않게 하려는 것입니다.
  (`config.php` 의 `max_queue`, 1 을 유지하세요)
- 한 장에 40~60초 걸립니다. ComfyUI 를 막 켠 직후 첫 장은 모델을 올리느라 더 걸립니다.
