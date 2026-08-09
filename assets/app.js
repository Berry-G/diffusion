'use strict';

const form = document.getElementById('gen-form');
const positive = document.getElementById('positive');
const negative = document.getElementById('negative');
const submitBtn = document.getElementById('submit-btn');
const statusBox = document.getElementById('status');
const statusText = document.getElementById('status-text');
const barFill = document.getElementById('bar-fill');
const errorBox = document.getElementById('error');
const resultBox = document.getElementById('result');
const resultImg = document.getElementById('result-img');
const resultLink = document.getElementById('result-link');
const downloadLink = document.getElementById('download');
const historyBox = document.getElementById('history');
const thumbs = document.getElementById('thumbs');
const phBox = document.getElementById('prompt-history');
const phList = document.getElementById('ph-list');

const POLL_MS = 1500;
const HISTORY_KEY = 'promptHistory';
const HISTORY_MAX = 20;
let polling = null;
let currentPromptId = null;

// 마지막에 쓴 프롬프트는 다음에 열 때 그대로 남아 있게 한다.
const saved = localStorage.getItem('lastPrompt');
if (saved && !positive.value) positive.value = saved;
const savedNeg = localStorage.getItem('lastNegative');
if (savedNeg) negative.value = savedNeg;

// ---------------------------------------------------------------- 프롬프트 기록
// 큐에 올라간 프롬프트만 이 기기의 localStorage 에 남긴다. 이미지 자체는 저장하지 않고,
// 어떤 파일이 나왔는지와 그걸 받아 갔는지만 기록한다.

function loadHistory() {
  try {
    const list = JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]');
    return Array.isArray(list) ? list : [];
  } catch (err) {
    return [];
  }
}

function saveHistory(list) {
  try {
    localStorage.setItem(HISTORY_KEY, JSON.stringify(list));
  } catch (err) {
    // 저장 공간이 찼으면 오래된 절반을 버리고 한 번 더 시도한다.
    try {
      localStorage.setItem(HISTORY_KEY, JSON.stringify(list.slice(0, Math.ceil(list.length / 2))));
    } catch (err2) {
      // 그래도 안 되면 기록은 포기한다. 생성 자체를 막을 이유는 없다.
    }
  }
}

function addHistory(pos, neg, promptId) {
  // 같은 프롬프트를 또 돌렸다면 목록에 쌓지 않고 맨 위로 올린다.
  const list = loadHistory().filter((it) => !(it.positive === pos && it.negative === neg));
  list.unshift({
    positive: pos,
    negative: neg,
    at: Date.now(),
    promptId: promptId,
    image: null,        // 완성되면 파일 정보만 채운다 (이미지 자체는 저장하지 않는다)
    downloadedAt: null,
  });
  saveHistory(list.slice(0, HISTORY_MAX));
  renderHistory();
}

/** 완성된 이미지의 파일 정보를 해당 기록에 붙인다. */
function attachImageToHistory(promptId, img) {
  const list = loadHistory();
  const item = list.find((it) => it.promptId === promptId);
  if (!item) return;
  item.image = {
    filename: img.filename,
    subfolder: img.subfolder || '',
    type: img.type || 'output',
  };
  saveHistory(list);
  renderHistory();
}

/**
 * 받기를 눌렀다고 표시한다.
 * 브라우저는 저장이 실제로 끝났는지 알려주지 않으므로, 정확히는 '받기를 눌렀음' 기록이다.
 */
function markDownloaded(match) {
  const list = loadHistory();
  const item = list.find(match);
  if (!item || item.downloadedAt) return;
  item.downloadedAt = Date.now();
  saveHistory(list);
  renderHistory();
}

function formatWhen(ts) {
  const d = new Date(ts);
  const time = d.toLocaleTimeString('ko-KR', { hour: '2-digit', minute: '2-digit' });
  const sameDay = d.toDateString() === new Date().toDateString();
  return sameDay ? time : `${d.getMonth() + 1}/${d.getDate()} ${time}`;
}

function renderHistory() {
  const list = loadHistory();
  phList.textContent = '';
  phBox.hidden = list.length === 0;

  list.forEach((item) => {
    const li = document.createElement('li');

    const pick = document.createElement('button');
    pick.type = 'button';
    pick.className = 'ph-item';

    // 프롬프트는 textContent 로만 넣는다 — HTML 로 해석되지 않게.
    const text = document.createElement('span');
    text.className = 'ph-text';
    text.textContent = item.positive;

    const when = document.createElement('time');
    when.className = 'ph-when';
    when.textContent = formatWhen(item.at);
    if (item.downloadedAt) {
      const badge = document.createElement('span');
      badge.className = 'ph-got';
      badge.textContent = '받음';
      when.append(' ', badge);
    }

    pick.append(text, when);
    pick.addEventListener('click', () => {
      positive.value = item.positive;
      negative.value = item.negative || '';
      positive.focus();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    li.appendChild(pick);

    // 완성된 이미지가 있으면 여기서 바로 다시 받을 수 있다.
    if (item.image) {
      const dl = document.createElement('a');
      dl.className = 'ph-dl' + (item.downloadedAt ? ' done' : '');
      dl.href = viewUrl(item.image, true);
      dl.setAttribute('download', '');
      dl.title = item.downloadedAt ? '받음 — 다시 받기' : '이미지 받기';
      dl.setAttribute('aria-label', dl.title);
      dl.textContent = item.downloadedAt ? '✓' : '⤓';
      dl.addEventListener('click', () => {
        // 목록을 다시 그리기 전에 브라우저가 내려받기를 시작하도록 한 박자 늦춘다.
        setTimeout(() => markDownloaded((it) => it.at === item.at), 0);
      });
      li.appendChild(dl);
    }

    phList.appendChild(li);
  });
}

renderHistory();

function viewUrl(img, download) {
  const p = new URLSearchParams({
    action: 'view',
    filename: img.filename,
    subfolder: img.subfolder || '',
    type: img.type || 'output',
  });
  if (download) p.set('download', '1');
  return 'api.php?' + p.toString();
}

function showError(message) {
  errorBox.textContent = message;
  errorBox.hidden = false;
}

function setBusy(busy) {
  submitBtn.disabled = busy;
  submitBtn.textContent = busy ? '생성 중…' : '생성하기';
}

/**
 * 진행바.
 * ComfyUI 는 폴링으로 정확한 퍼센트를 주지 않으므로 평균 소요 시간으로 채우되,
 * 실제로 끝날 때까지 99% 를 넘기지 않는다 — 다 찼는데 안 끝나면 더 답답하니까.
 */
function startProgress(etaSeconds) {
  const started = Date.now();
  return setInterval(() => {
    const elapsed = (Date.now() - started) / 1000;
    const pct = Math.min(99, (elapsed / etaSeconds) * 100);
    barFill.style.width = pct.toFixed(1) + '%';
  }, 200);
}

function addThumb(img) {
  const a = document.createElement('a');
  a.href = viewUrl(img, false);
  a.target = '_blank';
  a.rel = 'noopener';
  const el = document.createElement('img');
  el.src = viewUrl(img, false);
  el.alt = '';
  a.appendChild(el);
  thumbs.prepend(a);
  historyBox.hidden = false;
}

function showResult(images, promptId) {
  const first = images[0];
  resultImg.src = viewUrl(first, false);
  resultLink.href = viewUrl(first, false);
  downloadLink.href = viewUrl(first, true);
  resultBox.hidden = false;
  images.forEach(addThumb);
  attachImageToHistory(promptId, first);
}

// 결과를 받아 가면 그 기록에 표시해 둔다.
downloadLink.addEventListener('click', () => {
  if (currentPromptId) {
    markDownloaded((it) => it.promptId === currentPromptId);
  }
});

async function poll(promptId, progressTimer) {
  const res = await fetch('api.php?action=status&id=' + encodeURIComponent(promptId));
  const data = await res.json();

  if (data.state === 'queued') {
    statusText.textContent = `대기열 ${data.position}번째…`;
    return false;
  }
  if (data.state === 'running') {
    statusText.textContent = '그리는 중…';
    return false;
  }
  if (data.state === 'done') {
    clearInterval(progressTimer);
    barFill.style.width = '100%';
    statusText.textContent = '완성';
    showResult(data.images, promptId);
    return true;
  }
  if (data.state === 'error') {
    clearInterval(progressTimer);
    statusBox.hidden = true;
    showError(data.message || '생성에 실패했습니다.');
    return true;
  }
  // unknown — 서버가 재시작됐거나 작업이 취소된 경우
  clearInterval(progressTimer);
  statusBox.hidden = true;
  showError('작업을 찾을 수 없습니다. 다시 시도해 주세요.');
  return true;
}

/**
 * 진행 상황을 끝까지 따라간다.
 * 폰에서 화면을 끄거나 새로고침해도 이어받을 수 있도록 prompt_id 를 남겨 둔다.
 */
function startPolling(promptId, eta) {
  currentPromptId = promptId;
  localStorage.setItem('pendingPrompt', JSON.stringify({ id: promptId, eta: eta }));

  errorBox.hidden = true;
  statusBox.hidden = false;
  setBusy(true);

  const progressTimer = startProgress(eta);
  polling = setInterval(async () => {
    let finished = false;
    try {
      finished = await poll(promptId, progressTimer);
    } catch (err) {
      // 일시적인 네트워크 오류로 폴링을 멈추지는 않는다.
      return;
    }
    if (finished) {
      clearInterval(polling);
      polling = null;
      localStorage.removeItem('pendingPrompt');
      setBusy(false);
    }
  }, POLL_MS);
}

// 아직 끝나지 않은 작업이 있으면 이어서 지켜본다.
try {
  const pending = JSON.parse(localStorage.getItem('pendingPrompt') || 'null');
  if (pending && pending.id) {
    statusText.textContent = '이전 작업을 확인하는 중…';
    startPolling(pending.id, pending.eta || window.EST_SECONDS);
  }
} catch (err) {
  localStorage.removeItem('pendingPrompt');
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (polling) return;

  errorBox.hidden = true;
  resultBox.hidden = true;
  statusBox.hidden = false;
  barFill.style.width = '0%';
  statusText.textContent = '요청하는 중…';
  setBusy(true);

  localStorage.setItem('lastPrompt', positive.value);
  localStorage.setItem('lastNegative', negative.value);

  let data;
  try {
    const res = await fetch('api.php?action=generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ positive: positive.value, negative: negative.value }),
    });
    data = await res.json();
    if (!res.ok) throw new Error(data.error || '요청이 거절되었습니다.');
  } catch (err) {
    statusBox.hidden = true;
    setBusy(false);
    showError(err.message);
    return;
  }

  addHistory(positive.value, negative.value, data.prompt_id);
  statusText.textContent = data.queued > 0 ? `대기열 ${data.queued}번째…` : '그리는 중…';
  startPolling(data.prompt_id, data.eta || window.EST_SECONDS);
});
