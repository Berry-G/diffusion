<?php
$cfg = require __DIR__ . '/config.php';

// 워크플로우에 원래 들어 있던 네거티브를 기본값으로 채워 둡니다.
$defaultNegative = 'bad quality, worst quality, worst detail, sketch, censor';
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>이미지 생성</title>
<link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>

<main class="app">
  <h1>이미지 생성</h1>

  <form id="gen-form" autocomplete="off">
    <label class="field">
      <span class="label">프롬프트</span>
      <textarea id="positive" rows="7" placeholder="1girl, solo, standing, white background, best quality" required></textarea>
    </label>

    <label class="field">
      <span class="label">네거티브 <em>(빼고 싶은 것)</em></span>
      <textarea id="negative" rows="3"><?= htmlspecialchars($defaultNegative, ENT_QUOTES) ?></textarea>
    </label>

    <button type="submit" id="submit-btn" class="go">생성하기</button>
  </form>

  <section id="status" class="status" hidden>
    <div class="bar"><div class="bar-fill" id="bar-fill"></div></div>
    <p class="status-text" id="status-text">대기 중…</p>
  </section>

  <p id="error" class="error" hidden></p>

  <section id="result" class="result" hidden>
    <a id="result-link" href="#" target="_blank" rel="noopener">
      <img id="result-img" alt="생성된 이미지">
    </a>
    <a id="download" class="download" href="#" download>이미지 저장</a>
  </section>

  <section id="history" class="history" hidden>
    <h2>이번에 만든 것</h2>
    <div class="thumbs" id="thumbs"></div>
  </section>

  <section id="prompt-history" class="prompt-history" hidden>
    <h2>최근 프롬프트</h2>
    <ul class="ph-list" id="ph-list"></ul>
    <p class="ph-note">이 기기에만 저장됩니다. 눌러서 다시 불러올 수 있어요.</p>
  </section>
</main>

<script>window.EST_SECONDS = <?= (int)$cfg['est_seconds'] ?>;</script>
<script src="assets/app.js?v=1"></script>
</body>
</html>
