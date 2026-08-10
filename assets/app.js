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
const phBox = document.getElementById('prompt-history');
const phList = document.getElementById('ph-list');

const POLL_MS = 1500;
let polling = null;
let currentPromptId = null;

// 마지막에 쓴 프롬프트는 다음에 열 때 그대로 남아 있게 한다.
const saved = localStorage.getItem('lastPrompt');
if (saved && !positive.value) positive.value = saved;
const savedNeg = localStorage.getItem('lastNegative');
if (savedNeg) negative.value = savedNeg;

function viewUrl(img, opts) {
  const p = new URLSearchParams({
    action: 'view',
    filename: img.filename,
    subfolder: img.subfolder || '',
    type: img.type || 'output',
  });
  if (opts && opts.download) p.set('download', '1');
  if (opts && opts.thumb) p.set('thumb', '1');
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

// ------------------------------------------------------------------ 최근 목록
//
// 이 목록은 서버에서 가져옵니다. Tailscale 계정으로 묶여 있어서,
// PC 에서 만든 것을 폰에서도 이어 볼 수 있고 브라우저 기록을 지워도 남습니다.

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined && text !== null) node.textContent = String(text);
  return node;
}

function formatWhen(text) {
  const d = new Date(String(text).replace(' ', 'T'));
  if (isNaN(d)) return text;
  const time = d.toLocaleTimeString('ko-KR', { hour: '2-digit', minute: '2-digit' });
  const sameDay = d.toDateString() === new Date().toDateString();
  return sameDay ? time : `${d.getMonth() + 1}/${d.getDate()} ${time}`;
}

function historyRow(item) {
  const li = document.createElement('li');

  const pick = el('button', 'ph-item');
  pick.type = 'button';

  if (item.image) {
    const thumb = document.createElement('img');
    thumb.className = 'ph-thumb';
    thumb.loading = 'lazy';
    thumb.src = viewUrl(item.image, { thumb: true });
    thumb.alt = '';
    pick.appendChild(thumb);
  } else {
    pick.appendChild(el('span', 'ph-thumb ph-thumb-empty',
      item.status === 'error' ? '실패' : '…'));
  }

  const body = el('span', 'ph-body');
  body.appendChild(el('span', 'ph-text', item.positive));

  const when = el('time', 'ph-when', formatWhen(item.at));
  if (item.downloaded) {
    when.append(' ', el('span', 'ph-got', '받음'));
  }
  body.appendChild(when);
  pick.appendChild(body);

  pick.addEventListener('click', () => {
    positive.value = item.positive;
    negative.value = item.negative || '';
    positive.focus();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  li.appendChild(pick);

  if (item.image) {
    const dl = document.createElement('a');
    dl.className = 'ph-dl' + (item.downloaded ? ' done' : '');
    dl.href = viewUrl(item.image, { download: true });
    dl.setAttribute('download', '');
    dl.title = item.downloaded ? '받음 — 다시 받기' : '이미지 받기';
    dl.setAttribute('aria-label', dl.title);
    dl.textContent = item.downloaded ? '✓' : '⤓';
    // 받은 시각은 서버가 남기므로, 잠시 뒤 목록을 다시 가져와 표시를 맞춘다.
    dl.addEventListener('click', () => setTimeout(refreshHistory, 800));
    li.appendChild(dl);
  }

  return li;
}

async function refreshHistory() {
  let items = [];
  try {
    const res = await fetch('api.php?action=history');
    const data = await res.json();
    items = data.items || [];
  } catch (err) {
    return;   // 목록을 못 가져와도 생성 자체는 쓸 수 있어야 한다
  }

  phList.textContent = '';
  phBox.hidden = items.length === 0;
  items.forEach((item) => phList.appendChild(historyRow(item)));
}

// ------------------------------------------------------------------ 진행 표시

/**
 * ComfyUI 는 폴링으로 정확한 퍼센트를 주지 않으므로 평균 소요 시간으로 채우되,
 * 실제로 끝날 때까지 99% 를 넘기지 않는다 — 다 찼는데 안 끝나면 더 답답하니까.
 */
function startProgress(etaSeconds) {
  const started = Date.now();
  return setInterval(() => {
    const elapsed = (Date.now() - started) / 1000;
    barFill.style.width = Math.min(99, (elapsed / etaSeconds) * 100).toFixed(1) + '%';
  }, 200);
}

function showResult(images) {
  const first = images[0];
  resultImg.src = viewUrl(first, {});
  resultLink.href = viewUrl(first, {});
  downloadLink.href = viewUrl(first, { download: true });
  resultBox.hidden = false;
}

downloadLink.addEventListener('click', () => setTimeout(refreshHistory, 800));

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
    showResult(data.images);
    refreshHistory();
    return true;
  }
  if (data.state === 'error') {
    clearInterval(progressTimer);
    statusBox.hidden = true;
    showError(data.message || '생성에 실패했습니다.');
    refreshHistory();
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
      return;   // 일시적인 네트워크 오류로 폴링을 멈추지는 않는다
    }
    if (finished) {
      clearInterval(polling);
      polling = null;
      localStorage.removeItem('pendingPrompt');
      setBusy(false);
    }
  }, POLL_MS);
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

  statusText.textContent = data.queued > 0 ? `대기열 ${data.queued}번째…` : '그리는 중…';
  startPolling(data.prompt_id, data.eta || window.EST_SECONDS);
  refreshHistory();
});

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

refreshHistory();
