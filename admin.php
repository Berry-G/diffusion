<?php
/**
 * 관리자 페이지 — 만든 이미지와 그때 쓴 파라미터를 봅니다.
 *
 * 비밀번호는 config.local.php 에 둡니다. 비어 있으면 이 페이지는 열리지 않습니다.
 * Tailscale 안이라 해도 초대한 사람이 볼 수 있으므로 한 겹 더 막는 것입니다.
 */

session_start();

$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

const PER_PAGE = 24;

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------------------------ 로그인 처리
//
// 비밀번호는 DB 에 되돌릴 수 없는 해시로만 둡니다. 설정 파일에 평문으로 적으면
// 백업이나 화면 공유로 새어 나갈 수 있어서입니다.
// 아직 정한 적이 없으면 이 PC 앞에서 한 번 정하게 합니다 — 원격에서 먼저
// 들어와 비밀번호를 선점하는 것을 막기 위해서입니다.

$dbUp       = db($cfg) !== null;
$storedHash = $dbUp ? admin_password_hash($cfg) : null;
$needsSetup = $dbUp && $storedHash === null;

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

$loginError = null;
$setupError = null;
$notice     = null;

/** 새 비밀번호가 쓸 만한지 봅니다. 문제가 있으면 이유를 돌려줍니다. */
function password_problem(?string $new, ?string $confirm): ?string
{
    if ($new === null || $new === '') {
        return '비밀번호를 입력하세요.';
    }
    if (mb_strlen($new) < 8) {
        return '8자 이상으로 정해 주세요.';
    }
    if ($new !== $confirm) {
        return '두 번 입력한 값이 다릅니다.';
    }
    return null;
}

// 처음 정하기
if ($needsSetup && isset($_POST['new_password'])) {
    if (!is_local_request()) {
        $setupError = '비밀번호는 이 PC 앞에서 먼저 정해야 합니다.';
    } elseif ($problem = password_problem($_POST['new_password'], $_POST['confirm'] ?? null)) {
        $setupError = $problem;
    } elseif (set_admin_password($cfg, $_POST['new_password'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $setupError = '저장하지 못했습니다. MariaDB 연결을 확인하세요.';
    }
}

// 로그인
if (!$needsSetup && isset($_POST['password'])) {
    if (verify_admin_password($cfg, (string)$_POST['password'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    }
    $loginError = '비밀번호가 다릅니다.';
    usleep(500000);   // 무차별 대입을 조금이라도 느리게
}

$authed = !empty($_SESSION['admin']);

// 로그인한 상태에서 바꾸기
if ($authed && isset($_POST['change_password'])) {
    if (!verify_admin_password($cfg, (string)($_POST['current_password'] ?? ''))) {
        $setupError = '지금 쓰는 비밀번호가 다릅니다.';
    } elseif ($problem = password_problem($_POST['change_password'], $_POST['confirm'] ?? null)) {
        $setupError = $problem;
    } elseif (set_admin_password($cfg, $_POST['change_password'])) {
        $notice = '비밀번호를 바꿨습니다.';
    } else {
        $setupError = '저장하지 못했습니다.';
    }
}

$showSettings = $authed && isset($_GET['settings']);

// ------------------------------------------------------------------ 목록 조회
$rows = [];
$tagsByGen = [];
$popularTags = [];
$total = 0;
$dbError = null;

$q      = trim((string)($_GET['q'] ?? ''));
$tag    = trim((string)($_GET['tag'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));

if ($authed && !$showSettings) {
    $pdo = db($cfg);
    if (!$pdo) {
        $dbError = 'MariaDB 에 연결하지 못했습니다. Laragon 에서 MySQL 이 켜져 있는지 확인하세요.';
    } else {
        try {
            $where  = [];
            $params = [];

            if ($q !== '') {
                // 같은 이름의 자리표시자를 두 번 쓰면 네이티브 프리페어에서 거부당한다.
                $where[] = '(g.positive LIKE :q_pos OR g.negative LIKE :q_neg)';
                $params['q_pos'] = '%' . $q . '%';
                $params['q_neg'] = '%' . $q . '%';
            }
            if (in_array($status, ['queued', 'done', 'error'], true)) {
                $where[] = 'g.status = :status';
                $params['status'] = $status;
            }
            if ($tag !== '') {
                $where[] = 'EXISTS (SELECT 1 FROM generation_tags gt
                                      JOIN tags t ON t.id = gt.tag_id
                                     WHERE gt.generation_id = g.id AND t.name = :tag)';
                $params['tag'] = mb_strtolower($tag, 'UTF-8');
            }
            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $st = $pdo->prepare("SELECT COUNT(*) FROM generations g $whereSql");
            $st->execute($params);
            $total = (int)$st->fetchColumn();

            $offset = ($page - 1) * PER_PAGE;
            $st = $pdo->prepare(
                "SELECT g.id, g.prompt_id, g.positive, g.negative, g.seed, g.checkpoint,
                        g.sampler, g.scheduler, g.steps, g.cfg, g.width, g.height,
                        g.hires_steps, g.hires_denoise, g.loras, g.seeds, g.status,
                        g.error_message, g.filename, g.subfolder, g.file_type,
                        g.client_ip, g.ts_device, g.ts_user, g.ts_display,
                        g.source, g.created_at, g.completed_at, g.downloaded_at
                   FROM generations g
                   $whereSql
                  ORDER BY g.created_at DESC, g.id DESC
                  LIMIT " . PER_PAGE . " OFFSET " . (int)$offset
            );
            $st->execute($params);
            $rows = $st->fetchAll();

            if ($rows) {
                $ids  = array_column($rows, 'id');
                $in   = implode(',', array_map('intval', $ids));
                $tagSt = $pdo->query(
                    "SELECT gt.generation_id, t.name, gt.kind, gt.weight
                       FROM generation_tags gt
                       JOIN tags t ON t.id = gt.tag_id
                      WHERE gt.generation_id IN ($in)
                      ORDER BY gt.kind, t.name"
                );
                foreach ($tagSt as $r) {
                    $tagsByGen[$r['generation_id']][] = $r;
                }
            }

            $popularTags = $pdo->query(
                "SELECT t.name, COUNT(*) AS n
                   FROM generation_tags gt
                   JOIN tags t ON t.id = gt.tag_id
                  WHERE gt.kind = 'positive'
                  GROUP BY t.id
                  ORDER BY n DESC, t.name
                  LIMIT 40"
            )->fetchAll();
        } catch (Throwable $e) {
            $dbError = '조회 중 오류: ' . $e->getMessage();
        }
    }
}

/** 현재 필터를 유지한 채 일부만 바꾼 링크를 만듭니다. */
function link_with(array $changes): string
{
    $base = ['q' => $_GET['q'] ?? '', 'tag' => $_GET['tag'] ?? '',
             'status' => $_GET['status'] ?? '', 'page' => $_GET['page'] ?? ''];
    $merged = array_filter(array_merge($base, $changes), fn($v) => $v !== '' && $v !== null);
    return 'admin.php' . ($merged ? '?' . http_build_query($merged) : '');
}

function view_url(array $row, bool $thumb): string
{
    $p = [
        'action'    => 'view',
        'filename'  => $row['filename'],
        'subfolder' => $row['subfolder'] ?? '',
        'type'      => $row['file_type'] ?? 'output',
    ];
    if ($thumb) {
        $p['thumb'] = 1;
    }
    return 'api.php?' . http_build_query($p);
}

$pages = (int)ceil($total / PER_PAGE);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>생성 기록</title>
<link rel="stylesheet" href="assets/style.css?v=2">
<link rel="stylesheet" href="assets/admin.css?v=1">
</head>
<body>

<?php if (!$dbUp): ?>
  <main class="app">
    <h1>관리자 페이지</h1>
    <p class="error" style="display:block">
      MariaDB 에 연결하지 못했습니다.<br>
      Laragon 에서 MySQL 이 켜져 있는지 확인하세요.
    </p>
  </main>

<?php elseif ($needsSetup): ?>
  <main class="app">
    <h1>관리자 비밀번호 정하기</h1>
    <?php if (is_local_request()): ?>
      <p class="hint">
        처음 한 번만 정하면 됩니다. 되돌릴 수 없는 형태로만 저장되므로
        설정 파일이나 저장소에 비밀번호가 남지 않습니다.
      </p>
      <form method="post" class="login">
        <label class="field">
          <span class="label">새 비밀번호 <em>(8자 이상)</em></span>
          <input type="password" name="new_password" autocomplete="new-password" autofocus>
        </label>
        <label class="field">
          <span class="label">한 번 더</span>
          <input type="password" name="confirm" autocomplete="new-password">
        </label>
        <?php if ($setupError): ?><p class="error" style="display:block"><?= h($setupError) ?></p><?php endif; ?>
        <button type="submit" class="go">정하기</button>
      </form>
    <?php else: ?>
      <p class="error" style="display:block">
        아직 비밀번호가 정해지지 않았습니다.<br><br>
        이 PC 앞에서 <code>http://localhost/comfy/admin.php</code> 로 접속해
        먼저 정해 주세요. 원격에서 아무나 먼저 비밀번호를 차지하지 못하게
        일부러 막아 둔 것입니다.
      </p>
    <?php endif; ?>
  </main>

<?php elseif (!$authed): ?>
  <main class="app">
    <h1>생성 기록</h1>
    <form method="post" class="login">
      <label class="field">
        <span class="label">비밀번호</span>
        <input type="password" name="password" autocomplete="current-password" autofocus>
      </label>
      <?php if ($loginError): ?><p class="error" style="display:block"><?= h($loginError) ?></p><?php endif; ?>
      <button type="submit" class="go">들어가기</button>
    </form>
  </main>

<?php elseif ($showSettings): ?>
  <main class="app">
    <header class="admin-head">
      <h1>비밀번호 바꾸기</h1>
      <a class="logout" href="admin.php">← 목록</a>
    </header>
    <form method="post" class="login">
      <label class="field">
        <span class="label">지금 쓰는 비밀번호</span>
        <input type="password" name="current_password" autocomplete="current-password">
      </label>
      <label class="field">
        <span class="label">새 비밀번호 <em>(8자 이상)</em></span>
        <input type="password" name="change_password" autocomplete="new-password">
      </label>
      <label class="field">
        <span class="label">한 번 더</span>
        <input type="password" name="confirm" autocomplete="new-password">
      </label>
      <?php if ($setupError): ?><p class="error" style="display:block"><?= h($setupError) ?></p><?php endif; ?>
      <?php if ($notice): ?><p class="notice"><?= h($notice) ?></p><?php endif; ?>
      <button type="submit" class="go">바꾸기</button>
    </form>
  </main>

<?php else: ?>
  <main class="app admin">
    <header class="admin-head">
      <h1>생성 기록 <span class="count"><?= number_format($total) ?></span></h1>
      <span class="head-actions">
        <a class="logout" href="admin.php?settings=1">비밀번호</a>
        <a class="logout" href="admin.php?logout=1">나가기</a>
      </span>
    </header>

    <?php if ($dbError): ?>
      <p class="error" style="display:block"><?= h($dbError) ?></p>
    <?php endif; ?>

    <form class="filters" method="get">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="프롬프트 검색">
      <select name="status">
        <option value="">상태 전체</option>
        <?php foreach (['done' => '완성', 'queued' => '대기/생성중', 'error' => '실패'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($tag !== ''): ?>
        <input type="hidden" name="tag" value="<?= h($tag) ?>">
      <?php endif; ?>
      <button type="submit">찾기</button>
      <?php if ($q !== '' || $tag !== '' || $status !== ''): ?>
        <a class="clear" href="admin.php">초기화</a>
      <?php endif; ?>
    </form>

    <?php if ($tag !== ''): ?>
      <p class="active-tag">태그 <strong><?= h($tag) ?></strong> 로 걸러진 결과
        <a href="<?= h(link_with(['tag' => null, 'page' => null])) ?>">해제</a></p>
    <?php endif; ?>

    <?php if ($popularTags && $tag === '' && $q === ''): ?>
      <div class="tag-cloud">
        <?php foreach ($popularTags as $t): ?>
          <a href="<?= h(link_with(['tag' => $t['name'], 'page' => null])) ?>"><?= h($t['name']) ?><i><?= $t['n'] ?></i></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <p class="empty"><?= $total === 0 && $q === '' && $tag === '' && $status === ''
          ? '아직 기록이 없습니다. 웹 폼으로 한 장 만들거나, tools/backfill.php 로 기존 이미지를 불러오세요.'
          : '조건에 맞는 기록이 없습니다.' ?></p>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($rows as $row):
            $meta = [
                'id'        => $row['id'],
                'positive'  => $row['positive'],
                'negative'  => $row['negative'],
                'tags'      => $tagsByGen[$row['id']] ?? [],
                'params'    => [
                    '체크포인트'   => $row['checkpoint'],
                    '샘플러'       => $row['sampler'] . ' / ' . $row['scheduler'],
                    '스텝'         => $row['steps'],
                    'CFG'          => $row['cfg'],
                    '해상도'       => $row['width'] && $row['height'] ? $row['width'] . ' x ' . $row['height'] : null,
                    'Hires'        => $row['hires_steps'] ? $row['hires_steps'] . '스텝 / denoise ' . rtrim(rtrim((string)$row['hires_denoise'], '0'), '.') : null,
                    '시드'         => $row['seed'],
                    '만든 기기'    => $row['ts_device'] ?: $row['client_ip'],
                    '만든 사람'    => $row['ts_display'] ?: $row['ts_user'],
                    '만든 때'      => $row['created_at'],
                    '받은 때'      => $row['downloaded_at'],
                ],
                'loras'     => json_decode((string)$row['loras'], true) ?: [],
                'seeds'     => json_decode((string)$row['seeds'], true) ?: [],
                'status'    => $row['status'],
                'error'     => $row['error_message'],
                'full'      => $row['filename'] ? view_url($row, false) : null,
                'download'  => $row['filename'] ? view_url($row, false) . '&download=1' : null,
            ];
        ?>
          <article class="card status-<?= h($row['status']) ?>"
                   data-meta="<?= h(json_encode($meta, JSON_UNESCAPED_UNICODE)) ?>">
            <?php if ($row['filename']): ?>
              <img loading="lazy" src="<?= h(view_url($row, true)) ?>" alt="">
            <?php else: ?>
              <div class="no-image"><?= $row['status'] === 'error' ? '실패' : '생성 중' ?></div>
            <?php endif; ?>
            <p class="card-prompt"><?= h(mb_substr($row['positive'], 0, 90)) ?></p>
            <time><?= h(substr((string)$row['created_at'], 5, 11)) ?></time>
          </article>
        <?php endforeach; ?>
      </div>

      <?php if ($pages > 1): ?>
        <nav class="pager">
          <?php if ($page > 1): ?><a href="<?= h(link_with(['page' => $page - 1])) ?>">← 이전</a><?php endif; ?>
          <span><?= $page ?> / <?= $pages ?></span>
          <?php if ($page < $pages): ?><a href="<?= h(link_with(['page' => $page + 1])) ?>">다음 →</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </main>

  <div id="detail" class="detail" hidden>
    <div class="detail-inner">
      <button type="button" class="detail-close" aria-label="닫기">×</button>
      <div class="detail-image"><img id="detail-img" alt=""></div>
      <div class="detail-body"></div>
    </div>
  </div>

  <script src="assets/admin.js?v=1"></script>
<?php endif; ?>

</body>
</html>
