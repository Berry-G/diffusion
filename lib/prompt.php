<?php
/**
 * 프롬프트에서 태그를 뽑고, 워크플로우에서 생성 파라미터를 뽑아냅니다.
 *
 * 태그 문법은 webui/danbooru 관례를 따릅니다.
 *   (tag:1.3)   가중치 1.3
 *   (tag)       1.1,  ((tag)) 1.21    — 괄호 하나당 1.1 배
 *   [tag]       0.909, [[tag]] 0.826  — 대괄호 하나당 1/1.1
 *   \(...\)     리터럴 괄호. 캐릭터 이름에 씁니다 — character\(series\)
 *   <lora:이름:0.9>  LoRA 지정. 태그가 아니므로 따로 걷어냅니다.
 */

/** 프롬프트에서 `<lora:이름:강도>` 를 뽑아냅니다. */
function extract_inline_loras(string $text): array
{
    $out = [];
    if (preg_match_all('/<lora:([^:>]+)(?::([\d.]+))?[^>]*>/i', $text, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $out[] = [
                'name'     => trim($one[1]),
                'strength' => isset($one[2]) ? (float)$one[2] : 1.0,
                'from'     => 'prompt',
            ];
        }
    }
    return $out;
}

/**
 * 프롬프트를 태그 목록으로 쪼갭니다.
 *
 * @return array<int, array{name: string, weight: float|null}>
 */
function parse_prompt_tags(string $text): array
{
    // LoRA 지정은 태그가 아니다.
    $text = preg_replace('/<[^>]*>/', ' ', $text);

    // 리터럴 괄호를 잠시 치워 두어야 가중치 괄호와 헷갈리지 않는다.
    $ESC_OPEN  = "\x01";
    $ESC_CLOSE = "\x02";
    $text = str_replace(['\\(', '\\)'], [$ESC_OPEN, $ESC_CLOSE], $text);

    $tags = [];
    $seen = [];

    foreach (preg_split('/[,\r\n]+/', $text) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }

        $weight = null;

        // (tag:1.3) — 명시적 가중치
        if (preg_match('/^\(+\s*(.+?)\s*:\s*([\d.]+)\s*\)+$/', $chunk, $m)) {
            $chunk  = $m[1];
            $weight = (float)$m[2];
        } else {
            // 괄호/대괄호 개수로 가중치가 정해지는 형태
            $open  = strlen($chunk) - strlen(ltrim($chunk, '('));
            $brack = strlen($chunk) - strlen(ltrim($chunk, '['));
            if ($open > 0) {
                $weight = round(pow(1.1, $open), 3);
            } elseif ($brack > 0) {
                $weight = round(pow(1 / 1.1, $brack), 3);
            }
            $chunk = trim($chunk, '()[]{} ');
        }

        // 남은 장식과 리터럴 괄호 복원
        $name = str_replace([$ESC_OPEN, $ESC_CLOSE], ['(', ')'], $chunk);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $name = mb_strtolower($name, 'UTF-8');

        if ($name === '' || mb_strlen($name) > 190) {
            continue;
        }
        // 같은 태그가 여러 번 나오면 가중치가 큰 쪽을 남긴다.
        if (isset($seen[$name])) {
            $i = $seen[$name];
            if (($weight ?? 1.0) > ($tags[$i]['weight'] ?? 1.0)) {
                $tags[$i]['weight'] = $weight;
            }
            continue;
        }
        $seen[$name] = count($tags);
        $tags[] = ['name' => $name, 'weight' => $weight];
    }

    return $tags;
}

/** "  832 x 1216  (portrait)" 에서 가로·세로를 뽑습니다. */
function parse_dimensions($value): array
{
    if (is_string($value) && preg_match('/(\d+)\s*x\s*(\d+)/i', $value, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    return [null, null];
}

/**
 * API 포맷 워크플로우에서 생성 파라미터를 뽑아냅니다.
 *
 * 노드 번호를 되도록 쓰지 않고 class_type 으로 찾습니다.
 * 워크플로우를 고쳐 번호가 바뀌어도 계속 동작하게 하려는 것입니다.
 */
function extract_params(array $wf, array $nodeMap = []): array
{
    /** 값이 ["113", 0] 처럼 링크면 그 노드의 실제 값을 따라간다. */
    $resolve = function ($value) use ($wf) {
        if (!is_array($value) || count($value) !== 2 || !isset($wf[$value[0]])) {
            return $value;
        }
        $src = $wf[$value[0]];
        // PrimitiveFloat / PrimitiveInt 처럼 값 하나만 들고 있는 노드
        foreach (['value', 'INT', 'FLOAT'] as $key) {
            if (isset($src['inputs'][$key]) && !is_array($src['inputs'][$key])) {
                return $src['inputs'][$key];
            }
        }
        return null;
    };

    $byType = [];
    foreach ($wf as $id => $node) {
        $byType[$node['class_type'] ?? ''][$id] = $node['inputs'] ?? [];
    }

    // 출력이 아무 데서도 쓰이지 않는 노드는 실행되지 않는다.
    // 워크플로우에 남아 있는 고아 LoraLoader 를 실제로 먹인 것처럼
    // 기록하지 않기 위해 참조 여부를 미리 모아 둔다.
    $referenced = [];
    foreach ($wf as $node) {
        foreach ($node['inputs'] ?? [] as $val) {
            if (is_array($val) && count($val) === 2 && is_scalar($val[0])) {
                $referenced[(string)$val[0]] = true;
            }
        }
    }

    $p = [
        'checkpoint' => null, 'sampler' => null, 'scheduler' => null,
        'steps' => null, 'cfg' => null, 'width' => null, 'height' => null,
        'hires_steps' => null, 'hires_denoise' => null,
        'loras' => [], 'seeds' => [],
    ];

    // 모델. SDXL 계열은 체크포인트 하나로 끝나지만, UNET 을 따로 부르는
    // 워크플로우(Qwen 등)도 있어서 그쪽 이름도 같은 칸에 담는다.
    foreach ($byType['CheckpointLoaderSimple'] ?? [] as $in) {
        $p['checkpoint'] = $in['ckpt_name'] ?? null;
        break;
    }
    if ($p['checkpoint'] === null) {
        foreach (['UNETLoader' => 'unet_name', 'CheckpointLoader' => 'ckpt_name'] as $type => $key) {
            foreach ($byType[$type] ?? [] as $in) {
                if (!empty($in[$key]) && is_string($in[$key])) {
                    $p['checkpoint'] = $in[$key];
                    break 2;
                }
            }
        }
    }

    // 해상도
    foreach ($byType['SDXL Empty Latent Image (rgthree)'] ?? [] as $in) {
        [$p['width'], $p['height']] = parse_dimensions($in['dimensions'] ?? null);
        break;
    }
    if ($p['width'] === null) {
        foreach ($byType['EmptyLatentImage'] ?? [] as $in) {
            $p['width']  = is_numeric($in['width'] ?? null) ? (int)$in['width'] : null;
            $p['height'] = is_numeric($in['height'] ?? null) ? (int)$in['height'] : null;
            break;
        }
    }

    // 샘플러 — denoise 가 가장 큰 것을 1차(구도를 정하는 쪽)로 본다.
    $samplers = $byType['KSampler'] ?? [];
    uasort($samplers, fn($a, $b) => ($b['denoise'] ?? 1) <=> ($a['denoise'] ?? 1));
    $first = true;
    foreach ($samplers as $in) {
        if ($first) {
            $p['sampler']   = is_string($in['sampler_name'] ?? null) ? $in['sampler_name'] : null;
            $p['scheduler'] = is_string($in['scheduler'] ?? null) ? $in['scheduler'] : null;
            $p['steps']     = is_numeric($in['steps'] ?? null) ? (int)$in['steps'] : null;
            $cfg            = $resolve($in['cfg'] ?? null);
            $p['cfg']       = is_numeric($cfg) ? (float)$cfg : null;
            $first = false;
        } else {
            $p['hires_steps']   = is_numeric($in['steps'] ?? null) ? (int)$in['steps'] : null;
            $p['hires_denoise'] = is_numeric($in['denoise'] ?? null) ? (float)$in['denoise'] : null;
        }
    }

    // LoRA — Power Lora Loader 의 lora_N 과 개별 LoraLoader 를 모두 걷는다.
    foreach ($byType['Power Lora Loader (rgthree)'] ?? [] as $in) {
        foreach ($in as $key => $val) {
            if (str_starts_with((string)$key, 'lora_') && is_array($val) && isset($val['lora'])) {
                if (!empty($val['on'])) {
                    $p['loras'][] = [
                        'name'     => $val['lora'],
                        'strength' => (float)($val['strength'] ?? 1),
                        'from'     => 'power',
                    ];
                }
            }
        }
    }
    foreach ($byType['LoraLoader'] ?? [] as $id => $in) {
        if (!isset($referenced[(string)$id])) {
            continue;   // 연결되지 않은 노드 — 실행되지 않으므로 기록하지 않는다
        }
        if (!empty($in['lora_name']) && is_string($in['lora_name'])) {
            $p['loras'][] = [
                'name'     => $in['lora_name'],
                'strength' => (float)($in['strength_model'] ?? 1),
                'from'     => 'node',
            ];
        }
    }

    // 시드 — 노드별로 다 남긴다. 어떤 노드가 무엇인지 알 수 있게 종류도 같이.
    foreach ($wf as $id => $node) {
        if (isset($node['inputs']['seed']) && is_numeric($node['inputs']['seed'])) {
            $p['seeds'][$id] = [
                'type' => $node['class_type'] ?? '?',
                'seed' => (int)$node['inputs']['seed'],
            ];
        }
    }

    // 프롬프트에 적어 넣은 <lora:...> 도 함께 기록한다.
    $positiveNode = $nodeMap['positive'] ?? null;
    $text = $positiveNode !== null ? ($wf[$positiveNode]['inputs']['populated_text'] ?? '') : '';
    if ($text === '') {
        foreach ($byType['ImpactWildcardEncode'] ?? [] as $in) {
            $text = $in['populated_text'] ?? '';
            break;
        }
    }
    foreach (extract_inline_loras((string)$text) as $lora) {
        $p['loras'][] = $lora;
    }

    return $p;
}
