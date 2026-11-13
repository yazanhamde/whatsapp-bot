"use strict";
/* ============================================================
   قسم: صور غير معرّفة — تدريب البوت يدوياً
   sections/unresolvedImages.js
   ============================================================ */

const uiState = {
  tab:      'pending',
  page:     1,
  pages:    1,
  counts:   { pending: 0, learned: 0, ignored: 0 },
  items:    [],
};

let _pickerImageId = null;
let _allProducts   = null;

/* ── CSS ── */
const UI_STYLES = `
  .ui-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
  .ui-tab  { padding:7px 16px; border-radius:20px; border:1px solid var(--line-lt);
              background:transparent; color:var(--ink-dim); font-size:0.82rem;
              cursor:pointer; transition:.15s; position:relative; }
  .ui-tab.active { background:var(--accent); color:#fff; border-color:var(--accent); }
  .ui-tab .ui-badge { position:absolute; top:-6px; right:-6px; min-width:18px; height:18px;
    border-radius:9px; background:#e74c3c; color:#fff; font-size:0.68rem;
    display:flex; align-items:center; justify-content:center; padding:0 4px; }

  .ui-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; }

  .ui-card { background:var(--surface-lt); border-radius:14px; overflow:hidden;
             border:1px solid var(--line-lt); display:flex; flex-direction:column; }
  .ui-card-img { width:100%; aspect-ratio:1/1; object-fit:cover; background:#1a1a2e;
                 display:block; cursor:zoom-in; }
  .ui-card-img-ph { width:100%; aspect-ratio:1/1; background:var(--surface-lt);
    display:flex; align-items:center; justify-content:center;
    color:var(--ink-dim); font-size:2.5rem; }
  .ui-card-body { padding:10px 12px 12px; flex:1; display:flex; flex-direction:column; gap:6px; }
  .ui-card-meta { font-size:0.75rem; color:var(--ink-dim); }
  .ui-card-meta span { background:rgba(255,255,255,.06); border-radius:6px;
                        padding:2px 7px; margin-left:4px; display:inline-block; }
  .ui-card-desc { font-size:0.8rem; color:var(--ink-dim); line-height:1.5;
                   max-height:56px; overflow:hidden; }
  .ui-card-phone { font-size:0.75rem; color:var(--ink-dim); direction:ltr; }
  .ui-card-date { font-size:0.7rem; color:var(--ink-dim); margin-top:2px; }

  .ui-match-box { background:rgba(0,200,150,.08); border:1px solid rgba(0,200,150,.2);
                  border-radius:8px; padding:8px; display:flex; align-items:center;
                  gap:8px; margin-top:4px; }
  .ui-match-img { width:36px; height:36px; border-radius:6px; object-fit:cover; }
  .ui-match-name { font-size:0.78rem; color:#00c896; font-weight:600; }
  .ui-match-code { font-size:0.72rem; color:var(--ink-dim); }

  .ui-actions { display:flex; gap:6px; margin-top:auto; padding-top:8px; }
  .ui-btn-train { flex:1; padding:8px; border-radius:10px; background:var(--accent);
                  color:#fff; border:none; font-size:0.8rem; cursor:pointer;
                  font-weight:600; transition:.15s; }
  .ui-btn-train:hover { opacity:.85; }
  .ui-btn-train:disabled { opacity:.4; cursor:not-allowed; }
  .ui-btn-train.secondary { background:var(--surface-lt); color:var(--ink-dim); font-weight:400; }
  .ui-btn-ignore { padding:8px 10px; border-radius:10px; background:transparent;
                   color:var(--ink-dim); border:1px solid var(--line-lt);
                   font-size:0.8rem; cursor:pointer; transition:.15s; }
  .ui-btn-ignore:hover { border-color:#e74c3c; color:#e74c3c; }
  .ui-btn-add-product { padding:8px 10px; border-radius:10px;
                        background:rgba(95,174,126,0.12); color:var(--ok);
                        border:1px solid rgba(95,174,126,0.3);
                        font-size:0.8rem; cursor:pointer; transition:.15s; }
  .ui-btn-add-product:hover { background:rgba(95,174,126,0.22); }

  .ui-picker { display:flex; flex-direction:column; gap:10px; }
  .ui-picker-title { font-size:0.9rem; font-weight:700; }
  .ui-picker-search { padding:10px 12px; border-radius:10px;
    border:1px solid var(--line-lt); background:rgba(0,0,0,.2);
    color:var(--ink); font-size:0.88rem; outline:none; width:100%; box-sizing:border-box; }
  .ui-picker-list { max-height:340px; overflow-y:auto; display:flex; flex-direction:column; gap:6px; }
  .ui-picker-item { display:flex; align-items:center; gap:10px; padding:8px 10px;
    border-radius:10px; background:var(--surface-lt); cursor:pointer;
    border:1px solid transparent; transition:.12s; }
  .ui-picker-item:hover { border-color:var(--accent); }
  .ui-picker-item img { width:40px; height:40px; border-radius:7px; object-fit:cover; flex-shrink:0; }
  .ui-picker-img-ph { width:40px; height:40px; border-radius:7px;
    background:var(--line-lt); flex-shrink:0; display:flex;
    align-items:center; justify-content:center; font-size:1.2rem; }
  .pi-name { font-size:0.84rem; font-weight:600; }
  .pi-code { font-size:0.75rem; color:var(--ink-dim); }

  .ui-pager { display:flex; justify-content:center; align-items:center;
              gap:10px; margin-top:16px; }
  .ui-pager button { padding:6px 14px; border-radius:8px;
    border:1px solid var(--line-lt); background:transparent;
    color:var(--ink); cursor:pointer; font-size:0.82rem; }
  .ui-pager button:disabled { opacity:.3; cursor:not-allowed; }
  .ui-pager-info { font-size:0.8rem; color:var(--ink-dim); }

  .ui-empty { text-align:center; padding:60px 20px; color:var(--ink-dim); }
  .ui-empty-icon { font-size:3rem; margin-bottom:10px; }
  .ui-empty-msg { font-size:0.88rem; }
`;

/* ── Module ── */
VIEW_MODULES.unresolvedImages = {
  title: "صور غير معرّفة 📸",
  crumb: "تدريب البوت على الصور الجديدة",

  render(container) {
    // inject styles once
    if (!document.getElementById('ui-styles')) {
      const s = document.createElement('style');
      s.id = 'ui-styles';
      s.textContent = UI_STYLES;
      document.head.appendChild(s);
    }

    container.innerHTML = `
      <div class="ui-tabs">
        <button class="ui-tab active" data-tab="pending">
          ⏳ بانتظار التدريب
          <span class="ui-badge" id="uiBadgePending" hidden>0</span>
        </button>
        <button class="ui-tab" data-tab="learned">✅ تم التعلّم</button>
        <button class="ui-tab" data-tab="ignored">🚫 مُتجاهلة</button>
        <button class="ui-tab" data-tab="all">📋 الكل</button>
      </div>
      <div id="uiTabContent"></div>
    `;

    container.querySelectorAll('.ui-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        container.querySelectorAll('.ui-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        uiState.tab  = btn.dataset.tab;
        uiState.page = 1;
        loadImages();
      });
    });

    loadImages();
  },

  onRefresh() { loadImages(); }
};

/* ══════════════════════════════════════════════════════════════
   LOAD
══════════════════════════════════════════════════════════════ */
async function loadImages() {
  const c = $('uiTabContent');
  if (!c) return;
  renderSkeletonRows(c, 6);

  try {
    const params = new URLSearchParams({
      status:   uiState.tab,
      category: 'part',
      page:     uiState.page,
      per_page: 12,
    });
    const res = await apiFetch('/unresolved_images_api.php?' + params);

    if (!res.ok) throw new Error(res.message || 'خطأ في الاستجابة');

    const { items, total, page, pages, counts } = res.data;
    uiState.items  = items;
    uiState.page   = page;
    uiState.pages  = pages;
    uiState.counts = counts;

    // update pending badge
    const badge = $('uiBadgePending');
    if (badge) {
      badge.textContent = counts.pending || 0;
      badge.hidden = !counts.pending;
    }

    renderGrid(c, items, total);

  } catch (e) {
    c.innerHTML = `<div class="ui-empty">
      <div class="ui-empty-icon">⚠️</div>
      <div class="ui-empty-msg">${esc(e.message)}</div>
    </div>`;
  }
}

/* ══════════════════════════════════════════════════════════════
   RENDER GRID
══════════════════════════════════════════════════════════════ */
function renderGrid(container, items, total) {
  const emptyMsgs = {
    pending: 'لا توجد صور بانتظار التدريب 🎉',
    learned: 'لم يتعلّم البوت أي صورة بعد',
    ignored: 'لا توجد صور مُتجاهلة',
    all:     'لا توجد صور مسجّلة حتى الآن',
  };

  if (!items.length) {
    container.innerHTML = `<div class="ui-empty">
      <div class="ui-empty-icon">📷</div>
      <div class="ui-empty-msg">${emptyMsgs[uiState.tab] || 'لا توجد نتائج'}</div>
    </div>`;
    return;
  }

  const pager = uiState.pages > 1 ? `
    <div class="ui-pager">
      <button id="uiPrevBtn" ${uiState.page <= 1 ? 'disabled' : ''}>→ السابق</button>
      <span class="ui-pager-info">صفحة ${uiState.page} من ${uiState.pages} (${fmtInt(total)} صورة)</span>
      <button id="uiNextBtn" ${uiState.page >= uiState.pages ? 'disabled' : ''}>التالي ←</button>
    </div>` : '';

  container.innerHTML = `
    <div class="ui-grid">${items.map(buildCard).join('')}</div>
    ${pager}
  `;

  // pager events
  container.querySelector('#uiPrevBtn')?.addEventListener('click', () => {
    if (uiState.page > 1) { uiState.page--; loadImages(); }
  });
  container.querySelector('#uiNextBtn')?.addEventListener('click', () => {
    if (uiState.page < uiState.pages) { uiState.page++; loadImages(); }
  });

  // card events
  container.querySelectorAll('.ui-btn-train').forEach(btn => {
    btn.addEventListener('click', () => openPicker(+btn.dataset.id));
  });
  container.querySelectorAll('.ui-btn-ignore').forEach(btn => {
    btn.addEventListener('click', () => ignoreImage(+btn.dataset.id));
  });
  container.querySelectorAll('.ui-btn-add-product').forEach(btn => {
    btn.addEventListener('click', () => openAddProductModal(
      +btn.dataset.id,
      btn.dataset.name,
      btn.dataset.device,
      btn.dataset.brand
    ));
  });
  container.querySelectorAll('.ui-card-img[data-src]').forEach(img => {
    img.addEventListener('click', () => {
      openModal(`<img src="${img.dataset.src}" style="max-width:100%;border-radius:10px;">`);
    });
  });
}

/* ══════════════════════════════════════════════════════════════
   BUILD CARD
══════════════════════════════════════════════════════════════ */
function buildCard(img) {
  const imgEl = img.image_url
    ? `<img class="ui-card-img" data-src="${esc(img.image_url)}"
           src="${esc(img.image_url)}" loading="lazy" alt="صورة قطعة">`
    : `<div class="ui-card-img-ph">📦</div>`;

  const meta = [
    img.part_name   ? `<span>${esc(img.part_name)}</span>`   : '',
    img.device_type ? `<span>${esc(img.device_type)}</span>` : '',
    img.brand       ? `<span>${esc(img.brand)}</span>`       : '',
    img.code        ? `<span>${esc(img.code)}</span>`        : '',
  ].join('') || '<span>غير محدد</span>';

  const desc = img.visual_desc
    ? `<div class="ui-card-desc" title="${esc(img.visual_desc)}">${esc(img.visual_desc)}</div>` : '';

  const phone = img.customer_phone
    ? `<div class="ui-card-phone">📱 ${esc(img.customer_phone)}</div>` : '';

  const match = img.matched_product_name ? `
    <div class="ui-match-box">
      ${img.matched_product_image
        ? `<img class="ui-match-img" src="${esc(img.matched_product_image)}" alt="">`
        : ''}
      <div>
        <div class="ui-match-name">✅ ${esc(img.matched_product_name)}</div>
        <div class="ui-match-code">${esc(img.matched_product_code || '')}</div>
      </div>
    </div>` : '';

  const isPending = img.status === 'pending';
  const actions = `
    <div class="ui-actions">
      <button class="ui-btn-train${isPending ? '' : ' secondary'}" data-id="${img.id}">
        ${isPending ? '🔗 ربط بمنتج موجود' : '🔄 تغيير الربط'}
      </button>
      ${isPending ? `
        <button class="ui-btn-add-product"
          data-id="${img.id}"
          data-name="${esc(img.part_name || '')}"
          data-device="${esc(img.device_type || '')}"
          data-brand="${esc(img.brand || '')}"
          title="أضف كمنتج جديد">
          ➕
        </button>
        <button class="ui-btn-ignore" data-id="${img.id}" title="تجاهل">🚫</button>
      ` : ''}
    </div>`;

  return `
    <div class="ui-card">
      ${imgEl}
      <div class="ui-card-body">
        <div class="ui-card-meta">${meta}</div>
        ${desc}
        ${phone}
        ${match}
        <div class="ui-card-date">${fmtDate(img.created_at)}</div>
        ${actions}
      </div>
    </div>`;
}

/* ══════════════════════════════════════════════════════════════
   PRODUCT PICKER MODAL
══════════════════════════════════════════════════════════════ */
async function openPicker(imageId) {
  _pickerImageId = imageId;
  renderPicker();
}

function renderPicker() {
  const box = document.querySelector('#modalBox');

  // افتح الـ modal مع خانة البحث فاضية
  openModal(`
    <div class="ui-picker">
      <div class="ui-picker-title">🔍 اختر المنتج المناسب للصورة</div>
      <input class="ui-picker-search" id="uiPickerQ" placeholder="ابحث باسم القطعة أو الكود…" autocomplete="off">
      <div class="ui-picker-list" id="uiPickerList">
        <div style="padding:20px;text-align:center;color:var(--ink-dim);">اكتب للبحث في ${2406} منتج…</div>
      </div>
    </div>`);

  const searchEl = document.getElementById('uiPickerQ');
  const listEl   = document.getElementById('uiPickerList');

  function renderList(items) {
    if (!items.length) {
      listEl.innerHTML = `<div style="padding:20px;text-align:center;color:var(--ink-dim);">لا نتائج</div>`;
      return;
    }
    listEl.innerHTML = items.map(p => `
      <div class="ui-picker-item" data-pid="${p.id}">
        ${p.image_url
          ? `<img src="${esc(p.image_url)}" alt="">`
          : `<div class="ui-picker-img-ph">📦</div>`}
        <div>
          <div class="pi-name">${esc(p.name)}</div>
          <div class="pi-code">${esc(p.code || '')}</div>
        </div>
      </div>`).join('');

    listEl.querySelectorAll('.ui-picker-item').forEach(item => {
      item.addEventListener('click', () => trainImage(_pickerImageId, +item.dataset.pid));
    });
  }

  let _searchTimer = null;
  searchEl.addEventListener('input', () => {
    clearTimeout(_searchTimer);
    const q = searchEl.value.trim();
    if (q.length < 2) {
      listEl.innerHTML = `<div style="padding:20px;text-align:center;color:var(--ink-dim);">اكتب كلمتين على الأقل للبحث…</div>`;
      return;
    }
    listEl.innerHTML = `<div style="padding:20px;text-align:center;color:var(--ink-dim);">⏳ جاري البحث…</div>`;
    _searchTimer = setTimeout(async () => {
      try {
        const res = await apiFetch(`/products.php?per_page=50&page=1&search=${encodeURIComponent(q)}`);
        renderList(res.data?.items || []);
      } catch {
        listEl.innerHTML = `<div style="padding:20px;text-align:center;color:#e74c3c;">❌ خطأ في البحث</div>`;
      }
    }, 350);
  });

  searchEl.focus();
}

/* ══════════════════════════════════════════════════════════════
   ACTIONS
══════════════════════════════════════════════════════════════ */
async function trainImage(imageId, productId) {
  closeModal();
  try {
    const res = await apiFetch('/unresolved_images_api.php', {
      method: 'POST',
      body: JSON.stringify({ image_id: imageId, product_id: productId }),
    });
    if (!res.ok) throw new Error(res.message || 'فشل التدريب');
    showToast(res.message || '✅ تم التدريب بنجاح', 'success');
    loadImages();
  } catch (e) {
    showToast(e.message, 'error');
  }
}

async function ignoreImage(imageId) {
  try {
    const res = await apiFetch('/unresolved_images_api.php?id=' + imageId, { method: 'DELETE' });
    if (!res.ok) throw new Error(res.message || 'فشل');
    showToast('تم تجاهل الصورة', 'success');
    loadImages();
  } catch (e) {
    showToast(e.message, 'error');
  }
}

/* ══════════════════════════════════════════════════════════════
   ADD NEW PRODUCT MODAL — يفتح فورم إضافة منتج جديد
══════════════════════════════════════════════════════════════ */
function openAddProductModal(imageId, partName, device, brand) {
  // بناء اسم مقترح من بيانات التحليل
  const suggestedName = [partName, device, brand].filter(Boolean).join(' ').trim()
    || 'منتج جديد';

  openModal(`
    <div style="padding:4px">
      <div class="modal-head" style="padding:0 0 12px">
        <h3>➕ إضافة منتج جديد</h3>
      </div>

      <div style="background:rgba(95,174,126,0.08);border:1px solid rgba(95,174,126,0.2);border-radius:10px;padding:10px 12px;margin-bottom:14px;font-size:0.8rem;color:var(--ink-dim)">
        <b style="color:var(--ok)">🤖 تحليل البوت:</b>
        ${partName   ? `<div>القطعة: <b style="color:var(--ink)">${esc(partName)}</b></div>`   : ''}
        ${device     ? `<div>الجهاز: <b style="color:var(--ink)">${esc(device)}</b></div>`     : ''}
        ${brand      ? `<div>الماركة: <b style="color:var(--ink)">${esc(brand)}</b></div>`     : ''}
      </div>

      <div class="field" style="margin-bottom:10px">
        <label style="font-size:0.78rem;color:var(--ink-dim);display:block;margin-bottom:5px">اسم المنتج *</label>
        <input id="ap-name" class="field input"
          style="width:100%;background:rgba(0,0,0,.22);border:1px solid var(--line-lt);border-radius:10px;padding:10px 12px;color:var(--ink);font-size:0.9rem;outline:none;box-sizing:border-box"
          value="${esc(suggestedName)}" placeholder="اسم المنتج كما بالمخزن">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <div class="field">
          <label style="font-size:0.78rem;color:var(--ink-dim);display:block;margin-bottom:5px">الجهاز</label>
          <input id="ap-device"
            style="width:100%;background:rgba(0,0,0,.22);border:1px solid var(--line-lt);border-radius:10px;padding:10px 12px;color:var(--ink);font-size:0.88rem;outline:none;box-sizing:border-box"
            value="${esc(device)}" placeholder="غسالة، ثلاجة...">
        </div>
        <div class="field">
          <label style="font-size:0.78rem;color:var(--ink-dim);display:block;margin-bottom:5px">الماركة</label>
          <input id="ap-brand"
            style="width:100%;background:rgba(0,0,0,.22);border:1px solid var(--line-lt);border-radius:10px;padding:10px 12px;color:var(--ink);font-size:0.88rem;outline:none;box-sizing:border-box"
            value="${esc(brand)}" placeholder="LG, Samsung...">
        </div>
      </div>

      <div class="field" style="margin-bottom:14px">
        <label style="font-size:0.78rem;color:var(--ink-dim);display:block;margin-bottom:5px">الكمية</label>
        <input id="ap-qty" type="number" min="0" value="1"
          style="width:100%;background:rgba(0,0,0,.22);border:1px solid var(--line-lt);border-radius:10px;padding:10px 12px;color:var(--ink);font-size:0.88rem;outline:none;box-sizing:border-box">
      </div>

      <div style="display:flex;gap:8px">
        <button class="btn btn-primary" style="flex:1"
          onclick="submitNewProduct(${imageId})">
          ✅ إضافة للمخزن وتدريب البوت
        </button>
        <button class="btn btn-ghost" onclick="closeModal()">إلغاء</button>
      </div>
    </div>
  `);

  setTimeout(() => document.getElementById('ap-name')?.focus(), 100);
}

async function submitNewProduct(imageId) {
  const name   = document.getElementById('ap-name')?.value.trim();
  const device = document.getElementById('ap-device')?.value.trim();
  const brand  = document.getElementById('ap-brand')?.value.trim();
  const qty    = parseInt(document.getElementById('ap-qty')?.value || '1');

  if (!name) { showToast('اسم المنتج مطلوب', 'error'); return; }

  try {
    // ١. أضف المنتج بالمخزن
    const addRes = await apiFetch('/products.php', {
      method: 'POST',
      body: JSON.stringify({ name, device_type: device, brand, quantity: qty, condition_status: 'جديد' }),
    });
    if (!addRes.ok) throw new Error(addRes.message || 'فشل إضافة المنتج');

    const productId = addRes.data?.id;
    if (!productId) throw new Error('ما رجع ID للمنتج');

    // ٢. ربط الصورة بالمنتج الجديد (تدريب)
    const trainRes = await apiFetch('/unresolved_images_api.php', {
      method: 'POST',
      body: JSON.stringify({ image_id: imageId, product_id: productId }),
    });

    closeModal();

    if (trainRes.ok) {
      showToast(`✅ أُضيف "${name}" للمخزن وتعلّم البوت ربطه`, 'success');
    } else {
      showToast(`✅ أُضيف المنتج — ${trainRes.message || ''}`, 'success');
    }

    loadImages();

  } catch (e) {
    showToast(e.message, 'error');
  }
}
