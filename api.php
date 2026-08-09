<?php
/**
 * ComfyUI 프록시.
 *
 * 브라우저는 이 파일하고만 대화합니다. ComfyUI 자체는 127.0.0.1 에만 묶여 있어
 * 원격에서는 워크플로우를 열거나 고칠 수 없고, 여기서 허용한 세 가지 —
 * 생성 요청 / 진행 확인 / 결과 내려받기 — 만 가능합니다.
 */

$cfg = require __DIR__ . '/config.php';

/** ComfyUI 로 요청을 보내고 [본문, HTTP 상태] 를 돌려준다. */
function comfy_request(array $cfg, string $path, string $method = 'GET', $body = null): array
{
    $ch = curl_init($cfg['comfy_url'] . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    if ($res === false) {
        return [null, 0, $err];
    }
    return [$res, $code, null];
}

function comfy_json(array $cfg, string $path, string $method = 'GET', $body = null)
{
    [$res, $code, $err] = comfy_request($cfg, $path, $method, $body);
    if ($res === null) {
        fail(502, 'ComfyUI 에 연결할 수 없습니다. 서버가 켜져 있는지 확인하세요.', $err);
    }
    // 상태 코드를 그대로 넘겨준다. 400 대는 호출한 쪽에서 사람이 읽을 수 있게 풀어 쓴다.
    return [json_decode($res, true), $code];
}

function fail(int $status, string $message, $detail = null): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message, 'detail' => $detail], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 내려받을 때 쓸 파일 이름을 만든다.
 *
 * ComfyUI 가 만드는 이름은 `134356_8145206_00001_.png` 처럼 시각과 시드만 있고,
 * 날짜는 폴더(`web/2026-08-09`)에 들어 있다. 내려받고 나면 폴더가 사라져
 * 언제 만든 것인지 알 수 없으므로 날짜를 이름 앞으로 옮긴다.
 *
 *   web/2026-08-09 + 134356_8145206_00001_.png  ->  2026-08-09_134356_8145206.png
 */
function download_name(array $params): string
{
    $base = basename($params['filename']);
    $ext  = pathinfo($base, PATHINFO_EXTENSION);
    $stem = pathinfo($base, PATHINFO_FILENAME);

    // ComfyUI 가 붙이는 일련번호(_00001_)는 떼어 낸다.
    $stem = preg_replace('/_\d+_$/', '', $stem);

    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $params['subfolder'], $m)) {
        $stem = $m[1] . '_' . $stem;
    }

    return $stem . ($ext !== '' ? '.' . $ext : '');
}

function ok($data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';

// ---------------------------------------------------------------- 결과 이미지
// JSON 이 아니라 이미지 바이트를 그대로 흘려보내므로 다른 분기보다 먼저 처리한다.
if ($action === 'view') {
    $params = [
        'filename'  => $_GET['filename'] ?? '',
        'subfolder' => $_GET['subfolder'] ?? '',
        'type'      => $_GET['type'] ?? 'output',
    ];
    if ($params['filename'] === '') {
        fail(400, '파일 이름이 없습니다.');
    }
    // 경로 탈출 차단 — ComfyUI 출력 폴더 밖은 건드릴 수 없게 한다.
    foreach (['filename', 'subfolder'] as $k) {
        if (strpos($params[$k], '..') !== false) {
            fail(400, '잘못된 경로입니다.');
        }
    }
    if (!in_array($params['type'], ['output', 'temp'], true)) {
        fail(400, '잘못된 type 입니다.');
    }

    [$bytes, $code, $err] = comfy_request($cfg, '/view?' . http_build_query($params));
    if ($bytes === null || $code >= 400) {
        fail(502, '이미지를 가져오지 못했습니다.', $err);
    }

    $ext  = strtolower(pathinfo($params['filename'], PATHINFO_EXTENSION));
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
    header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=3600');
    if (isset($_GET['download'])) {
        header('Content-Disposition: attachment; filename="' . download_name($params) . '"');
    }
    echo $bytes;
    exit;
}

// ------------------------------------------------------------------- 생성 요청
if ($action === 'generate') {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        fail(400, '요청 본문을 읽지 못했습니다.');
    }

    $positive = trim((string)($in['positive'] ?? ''));
    $negative = trim((string)($in['negative'] ?? ''));
    if ($positive === '') {
        fail(400, '프롬프트를 입력하세요.');
    }
    if (mb_strlen($positive) > 8000 || mb_strlen($negative) > 8000) {
        fail(400, '프롬프트가 너무 깁니다.');
    }

    // 이미 그리는 중이면 받지 않는다.
    // GPU 가 한 대뿐이라, 이 PC 앞에서 직접 작업하는 도중에 원격 요청이
    // 끼어들면 그쪽 작업이 느려진다. 끼어들지 않는 것이 이 검사의 목적이다.
    [$q] = comfy_json($cfg, '/prompt');
    $waiting = $q['exec_info']['queue_remaining'] ?? 0;
    if ($waiting >= $cfg['max_queue']) {
        fail(429, '지금 다른 그림을 그리는 중입니다. 끝나면 다시 눌러 주세요.');
    }

    $wf = json_decode(file_get_contents($cfg['workflow']), true);
    if (!is_array($wf)) {
        fail(500, '워크플로우 파일을 읽지 못했습니다.');
    }

    $n = $cfg['nodes'];
    // ImpactWildcardEncode 는 실행할 때 populated_text 만 본다.
    // mode 를 fixed 로 두어야 입력한 프롬프트가 그대로 쓰인다.
    $wf[$n['positive']]['inputs']['wildcard_text']  = $positive;
    $wf[$n['positive']]['inputs']['populated_text'] = $positive;
    $wf[$n['positive']]['inputs']['mode']           = 'fixed';
    $wf[$n['negative']]['inputs']['text']           = $negative;

    // 매번 새 그림이 나오도록 시드를 새로 뽑는다.
    // 파일 이름에는 첫 번째 샘플러(그림의 구도를 정하는 쪽)의 시드를 남긴다.
    $mainSeed = null;
    foreach ($n['seeds'] as $id) {
        if (isset($wf[$id]['inputs']['seed'])) {
            $wf[$id]['inputs']['seed'] = random_int(0, 1125899906842624);
            if ($id === $n['main_seed']) {
                $mainSeed = $wf[$id]['inputs']['seed'];
            }
        }
    }

    // 저장 경로를 여기서 완성한다.
    $prefix = strtr($cfg['filename_prefix'], [
        '%date%' => date('Y-m-d'),
        '%time%' => date('His'),
        '%seed%' => (string)($mainSeed ?? 0),
    ]);
    foreach ($n['outputs'] as $id) {
        if (isset($wf[$id]['inputs']['filename_prefix'])) {
            $wf[$id]['inputs']['filename_prefix'] = $prefix;
        }
    }

    $clientId = bin2hex(random_bytes(8));
    [$res, $code] = comfy_json($cfg, '/prompt', 'POST', [
        'prompt'    => $wf,
        'client_id' => $clientId,
    ]);

    if ($code >= 400) {
        // ComfyUI 의 검증 오류를 사람이 읽을 수 있는 형태로 추린다.
        $msg = $res['error']['message'] ?? '워크플로우를 실행할 수 없습니다.';
        $detail = [];
        foreach (($res['node_errors'] ?? []) as $nodeId => $ne) {
            foreach (($ne['errors'] ?? []) as $e) {
                $detail[] = "노드 {$nodeId}: " . ($e['message'] ?? '') . ' ' . ($e['details'] ?? '');
            }
        }
        fail(400, $msg, $detail ?: ($res['error']['details'] ?? null));
    }

    ok([
        'prompt_id' => $res['prompt_id'],
        'queued'    => $waiting,
        'eta'       => $cfg['est_seconds'] * ($waiting + 1),
    ]);
}

// ------------------------------------------------------------------- 진행 확인
if ($action === 'status') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        fail(400, 'prompt_id 가 없습니다.');
    }

    [$hist] = comfy_json($cfg, '/history/' . rawurlencode($id));
    if (!empty($hist[$id])) {
        $entry  = $hist[$id];
        $status = $entry['status'] ?? [];

        if (($status['status_str'] ?? '') === 'error') {
            $why = '생성 중 오류가 발생했습니다.';
            foreach (($status['messages'] ?? []) as $m) {
                if (($m[0] ?? '') === 'execution_error') {
                    $why = $m[1]['exception_message'] ?? $why;
                }
            }
            ok(['state' => 'error', 'message' => $why]);
        }

        // 설정에 적힌 SaveImage 노드부터 찾고, 없으면 남은 출력에서 이미지를 긁는다.
        $images = [];
        $outputs = $entry['outputs'] ?? [];
        $order = array_merge($cfg['nodes']['outputs'], array_keys($outputs));
        foreach ($order as $nodeId) {
            foreach (($outputs[$nodeId]['images'] ?? []) as $img) {
                $images[] = [
                    'filename'  => $img['filename'],
                    'subfolder' => $img['subfolder'] ?? '',
                    'type'      => $img['type'] ?? 'output',
                ];
            }
            if ($images) {
                break;
            }
        }

        if ($images) {
            ok(['state' => 'done', 'images' => $images]);
        }
        if ($status['completed'] ?? false) {
            ok(['state' => 'error', 'message' => '완료됐지만 저장된 이미지를 찾지 못했습니다.']);
        }
    }

    // 아직 히스토리에 없다면 큐에 있거나 실행 중이다.
    [$queue] = comfy_json($cfg, '/queue');
    foreach (($queue['queue_running'] ?? []) as $item) {
        if (($item[1] ?? null) === $id) {
            ok(['state' => 'running']);
        }
    }
    $position = 0;
    foreach (($queue['queue_pending'] ?? []) as $item) {
        $position++;
        if (($item[1] ?? null) === $id) {
            ok(['state' => 'queued', 'position' => $position]);
        }
    }

    // 큐에도 히스토리에도 없다 — 취소됐거나 서버가 재시작된 경우.
    ok(['state' => 'unknown']);
}

fail(404, '알 수 없는 요청입니다.');
