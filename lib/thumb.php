<?php
/**
 * 목록에 쓸 작은 그림.
 *
 * 원본은 1080x1576 PNG 로 한 장에 1.5MB 쯤 됩니다. 관리자 페이지 그리드에
 * 수십 장을 그대로 띄우면 감당이 안 되므로, 긴 변을 줄인 JPEG 을 만들어 두고
 * 그것을 내보냅니다. 한 번 만든 것은 파일로 남겨 두었다가 재사용합니다.
 *
 * 원본은 ComfyUI 를 거치지 않고 출력 폴더에서 직접 읽습니다. 훨씬 빠르고,
 * 어차피 같은 PC 에 있는 파일입니다. 폴더 밖으로 새어 나가지 않도록
 * realpath 로 확인합니다.
 */

/** 썸네일을 만들어 내보냅니다. 이 함수는 돌아오지 않습니다. */
function serve_thumb(array $cfg, array $params): void
{
    $src = locate_source_image($cfg, $params);

    // 출력 폴더에서 못 찾으면 ComfyUI 에게 물어본다 (temp 이미지 등).
    if ($src === null) {
        [$bytes, $code] = comfy_request($cfg, '/view?' . http_build_query($params));
        if ($bytes === null || $code >= 400) {
            fail(404, '이미지를 찾지 못했습니다.');
        }
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            fail(415, '이미지를 읽지 못했습니다.');
        }
        output_thumb($img, $cfg['thumb_size']);
    }

    $cache = thumb_cache_path($cfg, $src);
    if (is_file($cache) && filemtime($cache) >= filemtime($src)) {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($cache));
        header('Cache-Control: private, max-age=86400');
        readfile($cache);
        exit;
    }

    $img = @imagecreatefromstring(file_get_contents($src));
    if (!$img) {
        fail(415, '이미지를 읽지 못했습니다.');
    }
    output_thumb($img, $cfg['thumb_size'], $cache);
}

/** 출력 폴더 안에서 실제 파일 경로를 찾습니다. 폴더 밖이면 null. */
function locate_source_image(array $cfg, array $params): ?string
{
    if (($params['type'] ?? 'output') !== 'output') {
        return null;
    }
    $root = realpath($cfg['output_dir']);
    if ($root === false) {
        return null;
    }

    $rel  = trim(str_replace('\\', '/', (string)$params['subfolder']), '/');
    $path = $root . DIRECTORY_SEPARATOR
          . ($rel !== '' ? str_replace('/', DIRECTORY_SEPARATOR, $rel) . DIRECTORY_SEPARATOR : '')
          . basename($params['filename']);

    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        return null;
    }
    // 출력 폴더 밖을 가리키면 거절한다.
    if (strncasecmp($real, $root, strlen($root)) !== 0) {
        return null;
    }
    return $real;
}

function thumb_cache_path(array $cfg, string $src): string
{
    $dir = $cfg['thumb_dir'];
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . DIRECTORY_SEPARATOR . md5($src) . '_' . $cfg['thumb_size'] . '.jpg';
}

/** 긴 변을 $size 로 줄여 JPEG 으로 내보냅니다. $cache 가 있으면 저장도 합니다. */
function output_thumb($img, int $size, ?string $cache = null): void
{
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, $size / max($w, $h));
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));

    $thumb = imagecreatetruecolor($tw, $th);
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);

    if ($cache !== null) {
        @imagejpeg($thumb, $cache, 82);
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    imagejpeg($thumb, null, 82);
    exit;
}
