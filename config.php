<?php
/**
 * 에셋 원격 생성기 설정
 *
 * 노드 ID는 workflow.json 워크플로우 기준입니다.
 * 워크플로우를 수정한 뒤 workflow_api.json 을 다시 내보냈다면
 * 아래 node 매핑도 함께 확인해야 합니다.
 */
return [
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
];
