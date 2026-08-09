'use strict';

// 카드를 누르면 그 이미지와 그때 쓴 파라미터를 함께 봅니다.

const detail = document.getElementById('detail');
const detailImg = document.getElementById('detail-img');
const detailBody = detail.querySelector('.detail-body');

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined && text !== null) node.textContent = String(text);
  return node;
}

function section(title, content) {
  const s = el('section');
  s.appendChild(el('h2', null, title));
  s.appendChild(content);
  return s;
}

function tagChips(tags) {
  const box = el('div', 'chips');
  tags.forEach((t) => {
    const a = el('a', t.kind === 'negative' ? 'neg' : null);
    a.href = 'admin.php?tag=' + encodeURIComponent(t.name);
    a.appendChild(el('span', null, t.name));
    // 가중치가 1 이 아닌 것만 보여줍니다 — 대부분은 가중치가 없습니다.
    if (t.weight !== null && Number(t.weight) !== 1) {
      a.appendChild(el('b', null, Number(t.weight)));
    }
    box.appendChild(a);
  });
  return box;
}

function paramTable(params, loras, seeds) {
  const table = el('table', 'param-table');
  const add = (label, value) => {
    if (value === null || value === undefined || value === '') return;
    const tr = el('tr');
    tr.appendChild(el('th', null, label));
    tr.appendChild(el('td', null, value));
    table.appendChild(tr);
  };

  Object.entries(params).forEach(([k, v]) => add(k, v));

  if (loras && loras.length) {
    const text = loras
      // 워크플로우에 0.49999999999999994 처럼 들어 있는 값을 그대로 보여주지 않는다.
      .map((l) => `${l.name} : ${+Number(l.strength).toFixed(3)}${l.from === 'prompt' ? ' (프롬프트)' : ''}`)
      .join('\n');
    add('LoRA', text);
  }
  if (seeds && Object.keys(seeds).length) {
    const text = Object.entries(seeds)
      .map(([id, s]) => `${s.type} #${id} : ${s.seed}`)
      .join('\n');
    add('노드별 시드', text);
  }
  return table;
}

function openDetail(meta) {
  detailBody.textContent = '';

  if (meta.full) {
    detailImg.src = meta.full;
    detailImg.parentElement.hidden = false;
  } else {
    detailImg.removeAttribute('src');
    detailImg.parentElement.hidden = true;
  }

  if (meta.status === 'error' && meta.error) {
    const p = el('p', 'error', meta.error);
    p.style.display = 'block';
    detailBody.appendChild(p);
  }

  const positive = el('p', 'prompt-text', meta.positive);
  detailBody.appendChild(section('프롬프트', positive));

  if (meta.negative) {
    detailBody.appendChild(section('네거티브', el('p', 'prompt-text', meta.negative)));
  }

  const positiveTags = (meta.tags || []).filter((t) => t.kind === 'positive');
  if (positiveTags.length) {
    detailBody.appendChild(section('태그 (누르면 같은 태그만 모아 봅니다)', tagChips(positiveTags)));
  }

  detailBody.appendChild(section('파라미터', paramTable(meta.params, meta.loras, meta.seeds)));

  if (meta.download) {
    const actions = el('div', 'detail-actions');
    const open = el('a', null, '원본 열기');
    open.href = meta.full;
    open.target = '_blank';
    open.rel = 'noopener';
    const dl = el('a', null, '내려받기');
    dl.href = meta.download;
    dl.setAttribute('download', '');
    actions.append(open, dl);
    detailBody.appendChild(actions);
  }

  detail.hidden = false;
  document.body.style.overflow = 'hidden';
}

function closeDetail() {
  detail.hidden = true;
  detailImg.removeAttribute('src');
  document.body.style.overflow = '';
}

document.querySelectorAll('.card').forEach((card) => {
  card.addEventListener('click', () => {
    try {
      openDetail(JSON.parse(card.dataset.meta));
    } catch (err) {
      // 데이터가 깨진 카드 하나 때문에 페이지 전체가 멈추지는 않게 한다.
    }
  });
});

detail.querySelector('.detail-close').addEventListener('click', closeDetail);
detail.addEventListener('click', (e) => {
  if (e.target === detail) closeDetail();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && !detail.hidden) closeDetail();
});
