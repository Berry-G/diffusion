<?php
/**
 * 이미 만들어 둔 이미지를 DB 로 불러옵니다.
 *
 * ComfyUI 의 SaveImage 는 PNG 안에 그때 실행한 워크플로우를 통째로 심어 둡니다.
 * 그래서 웹 폼을 만들기 전에 만든 그림도 프롬프트와 파라미터를 되살릴 수 있습니다.
 *
 *   php tools\backfill.php              전체 훑기
 *   php tools\backfill.php web          출력 폴더 아래 web 폴더만
 *   php tools\backfill.php --dry        DB 에 넣지 않고 무엇이 잡히는지만 확인
 *
 * 여러 번 돌려도 같은 파일이 중복으로 들어가지 않습니다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("명령줄에서만 실행할 수 있습니다.\n");
}

$root = dirname(__DIR__);
$cfg  = require $root . '/config.php';
require_once $root . '/lib/db.php';

$args   = array_slice($argv, 1);
$dryRun = in_array('--dry', $args, true);
$subdir = '';
foreach ($args as $a) {
    if ($a !== '--dry') {
        $subdir = trim($a, '/\\');
    }
}

$base = rtrim($cfg['output_dir'], '/\\');
$scan = $subdir !== '' ? $base . DIRECTORY_SEPARATOR . $subdir : $base;

if (!is_dir($scan)) {
    exit("폴더가 없습니다: $scan\n");
}

$pdo = $dryRun ? null : db($cfg);
if (!$dryRun && !$pdo) {
    exit("MariaDB 에 연결하지 못했습니다.\n");
}

echo "훑는 곳: $scan\n";
if ($dryRun) {
    echo "(--dry: DB 에 쓰지 않습니다)\n";
}
echo str_repeat('-', 60), "\n";

/**
 * PNG 의 tEXt/iTXt 청크에서 값을 꺼냅니다.
 * ComfyUI 는 'prompt' 에 API 포맷 워크플로우를, 'workflow' 에 화면용 그래프를 넣습니다.
 */
function png_text_chunks(string $path): array
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return [];
    }
    if (fread($fh, 8) !== "\x89PNG\r\n\x1a\n") {
        fclose($fh);
        return [];
    }

    $out = [];
    while (!feof($fh)) {
        $head = fread($fh, 8);
        if (strlen($head) < 8) {
            break;
        }
        $len  = unpack('N', substr($head, 0, 4))[1];
        $type = substr($head, 4, 4);

        if ($type === 'IDAT' || $type === 'IEND') {
            break;   // 실제 그림 데이터부터는 볼 필요가 없다
        }
        if ($len > 8 * 1024 * 1024) {
            break;   // 비정상적으로 큰 청크는 건드리지 않는다
        }

        $data = $len > 0 ? fread($fh, $len) : '';
        fseek($fh, 4, SEEK_CUR);   // CRC 건너뛰기

        if ($type === 'tEXt') {
            $p = strpos($data, "\0");
            if ($p !== false) {
                $out[substr($data, 0, $p)] = substr($data, $p + 1);
            }
        } elseif ($type === 'iTXt') {
            $parts = explode("\0", $data, 5);
            if (count($parts) === 5) {
                $out[$parts[0]] = $parts[4];
            }
        }
    }
    fclose($fh);
    return $out;
}

/** API 포맷 워크플로우에서 포지티브·네거티브를 찾아냅니다. */
function prompts_from_workflow(array $wf): array
{
    $positive = '';
    $negative = '';

    foreach ($wf as $node) {
        if (($node['class_type'] ?? '') === 'ImpactWildcardEncode') {
            $positive = (string)($node['inputs']['populated_text'] ?? '');
            break;
        }
    }

    // 네거티브는 KSampler 의 negative 입력을 따라가서 찾는다.
    foreach ($wf as $node) {
        if (($node['class_type'] ?? '') !== 'KSampler') {
            continue;
        }
        $link = $node['inputs']['negative'] ?? null;
        if (is_array($link) && isset($wf[$link[0]]['inputs']['text'])) {
            $negative = (string)$wf[$link[0]]['inputs']['text'];
            break;
        }
    }

    // 위에서 못 찾았으면 CLIPTextEncode 중 포지티브가 아닌 것을 쓴다.
    if ($positive === '') {
        foreach ($wf as $node) {
            if (($node['class_type'] ?? '') === 'CLIPTextEncode' && is_string($node['inputs']['text'] ?? null)) {
                if ($node['inputs']['text'] !== $negative) {
                    $positive = $node['inputs']['text'];
                    break;
                }
            }
        }
    }

    return [$positive, $negative];
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($scan, FilesystemIterator::SKIP_DOTS)
);

$found = $added = $updated = $skipped = $noMeta = 0;

foreach ($it as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'png') {
        continue;
    }
    $found++;

    $abs       = $file->getPathname();
    $relative  = ltrim(str_replace($base, '', $abs), '/\\');
    $subfolder = str_replace('\\', '/', dirname($relative));
    $subfolder = ($subfolder === '.' ? '' : $subfolder);
    $filename  = $file->getFilename();

    $chunks = png_text_chunks($abs);
    $raw    = $chunks['prompt'] ?? null;
    $wf     = $raw ? json_decode($raw, true) : null;

    if (!is_array($wf)) {
        $noMeta++;
        continue;
    }

    [$positive, $negative] = prompts_from_workflow($wf);
    $params   = extract_params($wf, $cfg['nodes']);
    $mainNode = $cfg['nodes']['main_seed'] ?? null;
    $seed     = ($mainNode !== null && isset($params['seeds'][$mainNode]))
        ? $params['seeds'][$mainNode]['seed']
        : (($params['seeds'] ? reset($params['seeds'])['seed'] : null));

    // 파일 경로로 고유 키를 만든다. 같은 파일을 두 번 넣지 않기 위해서다.
    $promptId = 'bf-' . md5($subfolder . '/' . $filename);
    $madeAt   = date('Y-m-d H:i:s', $file->getMTime());

    if ($dryRun) {
        printf("  %-46s %s\n", mb_strimwidth($relative, 0, 46, '…'),
            mb_strimwidth(preg_replace('/\s+/', ' ', $positive), 0, 60, '…'));
        $added++;
        continue;
    }

    try {
        $st = $pdo->prepare(
            'INSERT INTO generations
                (prompt_id, positive, negative, seed, checkpoint, sampler, scheduler,
                 steps, cfg, width, height, hires_steps, hires_denoise, loras, seeds,
                 status, filename, subfolder, file_type, source, created_at, completed_at, workflow_json)
             VALUES
                (:prompt_id, :positive, :negative, :seed, :checkpoint, :sampler, :scheduler,
                 :steps, :cfg, :width, :height, :hires_steps, :hires_denoise, :loras, :seeds,
                 "done", :filename, :subfolder, "output", "backfill", :made_at, :made_at2, :workflow)
             ON DUPLICATE KEY UPDATE
                 positive    = VALUES(positive),   negative      = VALUES(negative),
                 seed        = VALUES(seed),       checkpoint    = VALUES(checkpoint),
                 sampler     = VALUES(sampler),    scheduler     = VALUES(scheduler),
                 steps       = VALUES(steps),      cfg           = VALUES(cfg),
                 width       = VALUES(width),      height        = VALUES(height),
                 hires_steps = VALUES(hires_steps), hires_denoise = VALUES(hires_denoise),
                 loras       = VALUES(loras),      seeds         = VALUES(seeds)'
        );
        $st->execute([
            'prompt_id'     => $promptId,
            'positive'      => $positive,
            'negative'      => $negative,
            'seed'          => $seed,
            'checkpoint'    => $params['checkpoint'],
            'sampler'       => $params['sampler'],
            'scheduler'     => $params['scheduler'],
            'steps'         => $params['steps'],
            'cfg'           => $params['cfg'],
            'width'         => $params['width'],
            'height'        => $params['height'],
            'hires_steps'   => $params['hires_steps'],
            'hires_denoise' => $params['hires_denoise'],
            'loras'         => json_encode($params['loras'], JSON_UNESCAPED_UNICODE),
            'seeds'         => json_encode($params['seeds'], JSON_UNESCAPED_UNICODE),
            'filename'      => $filename,
            'subfolder'     => $subfolder,
            'made_at'       => $madeAt,
            'made_at2'      => $madeAt,
            'workflow'      => $raw,
        ]);

        // ON DUPLICATE KEY UPDATE 에서 rowCount 는 새로 넣으면 1, 값이 바뀌면 2, 그대로면 0.
        $rc = $st->rowCount();
        if ($rc === 1) {
            save_tags($pdo, (int)$pdo->lastInsertId(), $positive, $negative);
            $added++;
            printf("  + %s\n", mb_strimwidth($relative, 0, 70, '…'));
        } elseif ($rc >= 2) {
            $updated++;
        } else {
            $skipped++;
        }
    } catch (Throwable $e) {
        fprintf(STDERR, "  ! %s — %s\n", $relative, $e->getMessage());
    }
}

echo str_repeat('-', 60), "\n";
printf("PNG %d개 중  넣음 %d,  갱신 %d,  그대로 %d,  워크플로우 없음 %d\n",
    $found, $added, $updated, $skipped, $noMeta);

if ($noMeta > 0) {
    echo "\n'워크플로우 없음' 은 ComfyUI 밖에서 만들었거나 메타데이터가 지워진 파일입니다.\n";
}
