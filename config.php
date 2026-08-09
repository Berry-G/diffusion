<?php
/**
 * 에셋 원격 생성기 설정
 *
 * 노드 ID는 workflow.json 워크플로우 기준입니다.
 * 워크플로우를 수정한 뒤 workflow_api.json 을 다시 내보냈다면
 * 아래 node 매핑도 함께 확인해야 합니다.
 */
$config = [
    // ComfyUI 서버 주소. 127.0.0.1 로 묶어 두어 외부에서는 직접 접근할 수 없습니다.
    'comfy_url' => 'http://127.0.0.1:8000',

    // API 포맷으로 내보낸 워크플로우
    'workflow'  => __DIR__ . '/workflow_api.json',

    'nodes' => [
        // ImpactWildcardEncode — 포지티브 프롬프트 (webui 문법 <lora:...> 사용 가능)
        'positive' => '116',
        // CLIPTextEncode — 네거티브 프롬프트
        'negative' => '107',
        // 매 생성마다 새로 뽑을 시드를 가진 노드들
        'seeds'    => ['116', '114', '115', '117'],
        // 그림의 구도를 정하는 1차 KSampler — 파일 이름에 이 시드를 남깁니다.
        'main_seed' => '117',
        // 최종 이미지를 내놓는 SaveImage 노드 (앞에 있는 것부터 우선 사용)
        'outputs'  => ['92'],
    ],

    // 저장 경로 규칙. ComfyUI 출력 폴더(G:\comfy\output) 아래에 만들어집니다.
    // %date% %time% %seed% 는 생성 시각과 시드로 바뀝니다.
    // ComfyUI 화면에서 쓰던 %date:yyyy-MM-dd% 문법은 프론트엔드가 처리하는 것이라
    // API 로 보낼 때는 쓸 수 없어, 여기서 직접 값을 채웁니다.
    'filename_prefix' => 'web/%date%/%time%_%seed%',

    // 진행바 추정에 쓰는 1장당 평균 소요 시간(초). 실제 완료는 폴링으로 판정합니다.
    'est_seconds' => 60,

    // ComfyUI 가 이미 이만큼 일하고 있으면 새 요청을 받지 않습니다.
    // 1 = 무엇이든 그리는 중이면 거절. 이 PC 앞에서 직접 작업하는 동안
    // 원격 요청이 GPU 를 가로채지 않도록 하는 것이 목적이므로 1 을 유지하세요.
    'max_queue' => 1,

    // 생성 기록을 남길 MariaDB.
    // DB 가 꺼져 있어도 이미지 생성 자체는 계속됩니다 — 기록만 빠집니다.
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=diffusion;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',
    ],

    // 관리자 페이지 비밀번호.
    // 여기 적지 말고 config.local.php 에 넣으세요 (git 에 올라가지 않습니다).
    // 비어 있으면 관리자 페이지가 아예 열리지 않습니다.
    'admin_password' => '',

    // ComfyUI 출력 폴더. 썸네일을 만들 때 원본을 여기서 읽습니다.
    'output_dir' => 'G:\comfy\output',

    // 썸네일 캐시 위치와 긴 변의 픽셀 수
    'thumb_dir'  => __DIR__ . '/data/thumbs',
    'thumb_size' => 360,
];

// 이 기기에만 두는 설정(비밀번호, DB 계정 등)이 있으면 덮어씁니다.
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
