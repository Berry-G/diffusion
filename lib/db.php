<?php
/**
 * 생성 기록 저장.
 *
 * DB 는 부가 기능입니다. MariaDB 가 꺼져 있어도 이미지 생성은 그대로 되어야 하므로,
 * 여기의 함수는 실패하면 조용히 false 를 돌려주고 호출한 쪽을 막지 않습니다.
 */

require_once __DIR__ . '/prompt.php';
require_once __DIR__ . '/tailscale.php';

function db(array $cfg): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($tried) {
        return $pdo;
    }
    $tried = true;

    try {
        $pdo = new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 3,
        ]);
    } catch (Throwable $e) {
        error_log('[diffusion] DB 연결 실패: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

// ------------------------------------------------------------- 관리자 비밀번호
//
// 비밀번호는 되돌릴 수 없는 해시로만 저장합니다. 설정 파일에 평문으로 적어 두면
// 백업이나 화면 공유로 새어 나갈 수 있어서입니다.

/** 저장된 해시. 아직 정하지 않았으면 null. */
function admin_password_hash(array $cfg): ?string
{
    $pdo = db($cfg);
    if (!$pdo) {
        return null;
    }
    try {
        $hash = $pdo->query('SELECT password_hash FROM admin_auth WHERE id = 1')->fetchColumn();
        return $hash === false ? null : (string)$hash;
    } catch (Throwable $e) {
        error_log('[diffusion] 비밀번호 조회 실패: ' . $e->getMessage());
        return null;
    }
}

/** 비밀번호를 정하거나 바꿉니다. */
function set_admin_password(array $cfg, string $plain): bool
{
    $pdo = db($cfg);
    if (!$pdo) {
        return false;
    }
    try {
        $pdo->prepare(
            'INSERT INTO admin_auth (id, password_hash) VALUES (1, :hash)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
        )->execute(['hash' => password_hash($plain, PASSWORD_DEFAULT)]);
        return true;
    } catch (Throwable $e) {
        error_log('[diffusion] 비밀번호 저장 실패: ' . $e->getMessage());
        return false;
    }
}

/** 맞으면 true. 해시 방식이 낡았으면 조용히 새로 만들어 둡니다. */
function verify_admin_password(array $cfg, string $plain): bool
{
    $hash = admin_password_hash($cfg);
    if ($hash === null || !password_verify($plain, $hash)) {
        return false;
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        set_admin_password($cfg, $plain);
    }
    return true;
}

/** 이 PC 앞에서 온 요청인지. 최초 비밀번호 설정은 여기서만 허용합니다. */
function is_local_request(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** 생성 요청을 기록합니다. 반환값은 generations.id (실패하면 null). */
function record_generation(array $cfg, string $promptId, string $positive, string $negative,
                           array $wf, ?string $clientIp): ?int
{
    $pdo = db($cfg);
    if (!$pdo) {
        return null;
    }

    try {
        $p = extract_params($wf, $cfg['nodes']);

        // 파일 이름에 쓴 시드 = 1차 샘플러 시드
        $mainSeed = null;
        $mainNode = $cfg['nodes']['main_seed'] ?? null;
        if ($mainNode !== null && isset($p['seeds'][$mainNode])) {
            $mainSeed = $p['seeds'][$mainNode]['seed'];
        }

        // 누가 만들었는지. Tailscale 이 기기 이름과 계정을 알려줍니다.
        $who = tailscale_whois($cfg, $clientIp);

        $st = $pdo->prepare(
            'INSERT INTO generations
                (prompt_id, positive, negative, seed, checkpoint, sampler, scheduler,
                 steps, cfg, width, height, hires_steps, hires_denoise, loras, seeds,
                 status, client_ip, ts_device, ts_user, ts_display, source, workflow_json)
             VALUES
                (:prompt_id, :positive, :negative, :seed, :checkpoint, :sampler, :scheduler,
                 :steps, :cfg, :width, :height, :hires_steps, :hires_denoise, :loras, :seeds,
                 "queued", :client_ip, :ts_device, :ts_user, :ts_display, "web", :workflow)'
        );
        $st->execute([
            'ts_device'     => $who['device'],
            'ts_user'       => $who['user'],
            'ts_display'    => $who['display'],
            'prompt_id'     => $promptId,
            'positive'      => $positive,
            'negative'      => $negative,
            'seed'          => $mainSeed,
            'checkpoint'    => $p['checkpoint'],
            'sampler'       => $p['sampler'],
            'scheduler'     => $p['scheduler'],
            'steps'         => $p['steps'],
            'cfg'           => $p['cfg'],
            'width'         => $p['width'],
            'height'        => $p['height'],
            'hires_steps'   => $p['hires_steps'],
            'hires_denoise' => $p['hires_denoise'],
            'loras'         => json_encode($p['loras'], JSON_UNESCAPED_UNICODE),
            'seeds'         => json_encode($p['seeds'], JSON_UNESCAPED_UNICODE),
            'client_ip'     => $clientIp,
            'workflow'      => json_encode($wf, JSON_UNESCAPED_UNICODE),
        ]);

        $id = (int)$pdo->lastInsertId();
        save_tags($pdo, $id, $positive, $negative);
        return $id;
    } catch (Throwable $e) {
        error_log('[diffusion] 기록 실패: ' . $e->getMessage());
        return null;
    }
}

/** 프롬프트를 태그로 쪼개 저장합니다. */
function save_tags(PDO $pdo, int $generationId, string $positive, string $negative): void
{
    $rows = [];
    foreach (parse_prompt_tags($positive) as $t) {
        $rows[] = [$t['name'], 'positive', $t['weight']];
    }
    foreach (parse_prompt_tags($negative) as $t) {
        $rows[] = [$t['name'], 'negative', $t['weight']];
    }
    if (!$rows) {
        return;
    }

    $insTag = $pdo->prepare('INSERT INTO tags (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $insMap = $pdo->prepare(
        'INSERT INTO generation_tags (generation_id, tag_id, kind, weight)
         VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE weight = VALUES(weight)'
    );

    foreach ($rows as [$name, $kind, $weight]) {
        $insTag->execute([$name]);
        $tagId = (int)$pdo->lastInsertId();
        if ($tagId > 0) {
            $insMap->execute([$generationId, $tagId, $kind, $weight]);
        }
    }
}

/** 완성된 이미지 파일 정보를 기록에 붙입니다. */
function record_result(array $cfg, string $promptId, array $image): void
{
    $pdo = db($cfg);
    if (!$pdo) {
        return;
    }
    try {
        $pdo->prepare(
            'UPDATE generations
                SET status = "done", filename = ?, subfolder = ?, file_type = ?,
                    completed_at = COALESCE(completed_at, NOW())
              WHERE prompt_id = ?'
        )->execute([
            $image['filename'],
            $image['subfolder'] ?? '',
            $image['type'] ?? 'output',
            $promptId,
        ]);
    } catch (Throwable $e) {
        error_log('[diffusion] 결과 기록 실패: ' . $e->getMessage());
    }
}

/** 생성이 실패했음을 기록합니다. */
function record_error(array $cfg, string $promptId, string $message): void
{
    $pdo = db($cfg);
    if (!$pdo) {
        return;
    }
    try {
        $pdo->prepare(
            'UPDATE generations SET status = "error", error_message = ?,
                    completed_at = COALESCE(completed_at, NOW())
              WHERE prompt_id = ?'
        )->execute([mb_substr($message, 0, 2000), $promptId]);
    } catch (Throwable $e) {
        error_log('[diffusion] 오류 기록 실패: ' . $e->getMessage());
    }
}

/**
 * 접속한 사람이 만든 최근 기록.
 *
 * Tailscale 계정을 알 수 있으면 그것으로 묶습니다. 그래야 PC 에서 만든 것을
 * 폰에서도 이어 볼 수 있습니다. 계정을 모르면(로컬 접속 등) 주소로 묶습니다.
 */
function recent_generations(array $cfg, ?string $ip, int $limit = 20): array
{
    $pdo = db($cfg);
    if (!$pdo || $ip === null) {
        return [];
    }

    $who = tailscale_whois($cfg, $ip);
    $limit = max(1, min(100, $limit));

    if (!empty($who['user'])) {
        $sql  = "ts_user = ?";
        $args = [$who['user']];
    } else {
        // 같은 PC 에서 localhost 로 들어오면 ::1 과 127.0.0.1 이 섞인다.
        $ips  = in_array($ip, ['::1', '127.0.0.1'], true) ? ['::1', '127.0.0.1'] : [$ip];
        $sql  = 'client_ip IN (' . implode(',', array_fill(0, count($ips), '?')) . ')';
        $args = $ips;
    }

    try {
        $st = $pdo->prepare(
            "SELECT prompt_id, positive, negative, status, filename, subfolder, file_type,
                    ts_device, created_at, downloaded_at
               FROM generations
              WHERE $sql AND source = 'web'
              ORDER BY created_at DESC, id DESC
              LIMIT $limit"
        );
        $st->execute($args);
        return $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[diffusion] 최근 기록 조회 실패: ' . $e->getMessage());
        return [];
    }
}

/** 내려받은 시각을 남깁니다. */
function record_download(array $cfg, string $filename): void
{
    $pdo = db($cfg);
    if (!$pdo) {
        return;
    }
    try {
        $pdo->prepare(
            'UPDATE generations SET downloaded_at = NOW()
              WHERE filename = ? AND downloaded_at IS NULL'
        )->execute([$filename]);
    } catch (Throwable $e) {
        error_log('[diffusion] 다운로드 기록 실패: ' . $e->getMessage());
    }
}
