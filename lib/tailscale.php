<?php
/**
 * 접속한 사람이 누구인지 Tailscale 에게 물어봅니다.
 *
 * IP 만으로는 누가 만든 그림인지 알기 어렵습니다. Tailscale 은 주소를 주면
 * 그 기기의 이름과 로그인 계정을 돌려주므로, 그걸 기록해 둡니다.
 *
 *   100.x.x.x  ->  planner-laptop / someone@github (표시 이름)
 *
 * 이 PC 에서 localhost 로 들어오면 Tailscale 을 거치지 않아 조회되지 않습니다.
 * 그 경우는 '이 PC' 로 표시합니다.
 */

/**
 * @return array{device: ?string, user: ?string, display: ?string}
 */
function tailscale_whois(array $cfg, ?string $ip): array
{
    $unknown = ['device' => null, 'user' => null, 'display' => null];

    if ($ip === null || $ip === '') {
        return $unknown;
    }
    if (in_array($ip, ['::1', '127.0.0.1'], true)) {
        return ['device' => '이 PC', 'user' => null, 'display' => null];
    }

    $cached = whois_cache_get($cfg, $ip);
    if ($cached !== null) {
        return $cached;
    }

    $exe = $cfg['tailscale_exe'] ?? '';
    if (!is_file($exe)) {
        return $unknown;
    }

    // 2초 안에 답이 없으면 포기한다. 이것 때문에 생성이 늦어지면 안 된다.
    $cmd = 'cmd /c "' . escapeshellarg($exe) . ' whois --json ' . escapeshellarg($ip) . ' 2>nul"';
    $out = @shell_exec($cmd);

    $info = $unknown;
    if (is_string($out) && $out !== '') {
        $data = json_decode($out, true);
        if (is_array($data)) {
            $info = [
                'device'  => $data['Node']['ComputedName'] ?? null,
                'user'    => $data['UserProfile']['LoginName'] ?? null,
                'display' => $data['UserProfile']['DisplayName'] ?? null,
            ];
        }
    }

    whois_cache_put($cfg, $ip, $info);
    return $info;
}

/** 조회 결과는 잠깐 파일로 남겨 둡니다. 매번 프로세스를 띄우지 않기 위해서입니다. */
function whois_cache_path(array $cfg, string $ip): string
{
    $dir = dirname($cfg['thumb_dir']) . DIRECTORY_SEPARATOR . 'whois';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . DIRECTORY_SEPARATOR . md5($ip) . '.json';
}

function whois_cache_get(array $cfg, string $ip): ?array
{
    $path = whois_cache_path($cfg, $ip);
    if (!is_file($path) || (time() - filemtime($path)) > 3600) {
        return null;
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function whois_cache_put(array $cfg, string $ip, array $info): void
{
    @file_put_contents(whois_cache_path($cfg, $ip), json_encode($info, JSON_UNESCAPED_UNICODE));
}

/** 화면에 보여줄 짧은 이름. 기기 이름을 우선하고, 없으면 계정, 그것도 없으면 주소. */
function who_label(?string $device, ?string $user, ?string $ip): string
{
    if ($device !== null && $device !== '') {
        return $device;
    }
    if ($user !== null && $user !== '') {
        return $user;
    }
    return $ip ?? '알 수 없음';
}
