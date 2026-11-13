"use strict";
/* ============================================================
   قسم: دماغ البوت — مستوى التدريب والذاكرة الذكية
   ============================================================ */

const ragState = { tab: 'overview', page: 1, pages: 1, filterSource: '', filterQ: '' };

VIEW_MODULES.rag = {
  title: "دماغ البوت 🧠",
  crumb: "مستوى التدريب والذاكرة الذكية",
  render(container) {
    container.innerHTML = `
      <div class="rag-tabs">
        <button class="rag-tab active" data-tab="overview">📊 نظرة عامة</button>
        <button class="rag-tab" data-tab="memories">🧠 الذكريات</button>
        <button class="rag-tab" data-tab="train">🎓 تدريب</button>
        <button class="rag-tab" data-tab="aliases">🔤 أسماء عامية</button>
        <button class="rag-tab" data-tab="faults">🔧 الأعطال</button>
        <button class="rag-tab" data-tab="test">🧪 اختبار</button>
      </div>
      <div id="ragTabContent"></div>

      <style>
        .rag-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
        .rag-tab { padding:7px 14px; border-radius:20px; border:1px solid var(--line-lt);
          background:transparent; color:var(--ink-dim); font-size:0.82rem; cursor:pointer; transition:.15s; }
        .rag-tab.active { background:var(--accent); color:#fff; border-color:var(--accent); }
        .rag-stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; margin-bottom:18px; }
        .rag-stat { background:var(--surface-lt); border-radius:12px; padding:14px; text-align:center; }
        .rag-stat-num { font-size:1.8rem; font-weight:800; color:var(--accent); }
        .rag-stat-label { font-size:0.75rem; color:var(--ink-dim); margin-top:2px; }
        .rag-level { background:var(--surface-lt); border-radius:12px; padding:16px; margin-bottom:14px; }
        .rag-level-bar { height:12px; border-radius:6px; background:var(--line-lt); margin:8px 0; overflow:hidden; }
        .rag-level-fill { height:100%; border-radius:6px; background:linear-gradient(90deg,var(--accent),#00c896); transition:width .6s; }
        .mem-card { background:var(--surface-lt); border-radius:10px; padding:12px; margin-bottom:8px; }
        .mem-card-q { font-weight:600; font-size:0.88rem; margin-bottom:4px; }
        .mem-card-a { font-size:0.82rem; color:var(--ink-dim); margin-bottom:6px; line-height:1.5; }
        .mem-meta { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .pill-approved { background:rgba(0,200,150,.15); color:#00c896; }
        .pill-auto { background:rgba(100,120,200,.15); color:#7090dd; }
        .rag-search-bar { display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap; }
        .rag-search-bar input, .rag-search-bar select {
          flex:1; min-width:120px; padding:9px 12px; border-radius:10px;
          border:1px solid var(--line-lt); background:var(--surface-lt);
          color:var(--ink); font-size:0.85rem; outline:none; }
        .train-form { background:var(--surface-lt); border-radius:12px; padding:16px; margin-bottom:14px; }
        .train-form label { display:block; font-size:0.8rem; color:var(--ink-dim); margin-bottom:4px; }
        .train-form textarea, .train-form input {
          width:100%; padding:10px 12px; border-radius:10px; border:1px solid var(--line-lt);
          background:rgba(0,0,0,.15); color:var(--ink); font-size:0.85rem; outline:none;
          font-family:var(--sans); box-sizing:border-box; margin-bottom:10px; }
        .train-form textarea { min-height:80px; resize:vertical; }
        .test-result { background:var(--surface-lt); border-radius:10px; padding:14px; margin-top:12px; }
        .score-bar { display:flex; align-items:center; gap:8px; margin-top:4px; }
        .score-fill { height:8px; border-radius:4px; background:var(--accent); }
        .devices-row { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
        .device-pill { padding:4px 10px; border-radius:14px; background:var(--surface-lt);
          font-size:0.78rem; cursor:pointer; border:1px solid var(--line-lt); }
        .device-pill.active { background:var(--accent); color:#fff; border-color:var(--accent); }
      </style>
    `;

    container.querySelectorAll('.rag-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        container.querySelectorAll('.rag-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        ragState.tab = btn.dataset.tab;
        renderRagTab();
      });
    });

    renderRagTab();
  },
  onRefresh() { renderRagTab(); }
};

function renderRagTab() {
  const c = $('ragTabContent');
  if (!c) return;
  renderSkeletonRows(c, 3);
  const tab = ragState.tab;
  if (tab === 'overview') renderRagOverview(c);
  else if (tab === 'memories') renderRagMemories(c);
  else if (tab === 'train') renderRagTrain(c);
  else if (tab === 'aliases') renderRagAliases(c);
  else if (tab === 'faults') renderRagFaults(c);
  else if (tab === 'test') renderRagTest(c);
}

// ── نظرة عامة ────────────────────────────────────────────
async function renderRagOverview(c) {
  try {
    const res = await apiFetch('/rag.php?action=stats');
    const d   = res.data;
    const mem = d.memories || {};
    const total   = parseInt(mem.total   || 0);
    const approved= parseInt(mem.approved|| 0);
    const autoSaved=parseInt(mem.auto_saved||0);
    const uses    = parseInt(mem.total_uses||0);
    const avgScore= parseFloat(mem.avg_score||0);

    // حساب مستوى البوت
    let level = 'مبتدئ 🐣';
    let levelPct = 0;
    let levelColor = '#888';
    if (total >= 200) { level = 'خبير 🏆'; levelPct = 100; levelColor = '#00c896'; }
    else if (total >= 100) { level = 'محترف ⭐'; levelPct = 75; levelColor = '#7090dd'; }
    else if (total >= 50)  { level = 'متقدم 📈'; levelPct = 50; levelColor = '#f09030'; }
    else if (total >= 20)  { level = 'متوسط 🌱'; levelPct = 30; levelColor = '#f0b040'; }
    else if (total >= 5)   { level = 'مبتدئ+ 🐥'; levelPct = 15; levelColor = '#aaa'; }
    else { levelPct = Math.max(2, total * 2); }

    c.innerHTML = `
      <div class="rag-level">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-weight:700;font-size:1rem;">مستوى البوت: <span style="color:${levelColor}">${level}</span></div>
            <div style="font-size:0.78rem;color:var(--ink-dim);margin-top:2px;">${total} ذكرى محفوظة — ${approved} مصحّحة بواسطة رمزي</div>
          </div>
          <div style="font-size:2rem;">${levelPct >= 100 ? '🏆' : levelPct >= 75 ? '⭐' : levelPct >= 50 ? '📈' : levelPct >= 30 ? '🌱' : '🐣'}</div>
        </div>
        <div class="rag-level-bar">
          <div class="rag-level-fill" style="width:${levelPct}%;background:linear-gradient(90deg,${levelColor},${levelColor}cc);"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:0.72rem;color:var(--ink-faint);">
          <span>مبتدئ</span><span>متوسط (20)</span><span>متقدم (50)</span><span>محترف (100)</span><span>خبير (200+)</span>
        </div>
      </div>

      <div class="rag-stat-grid">
        <div class="rag-stat"><div class="rag-stat-num">${total}</div><div class="rag-stat-label">إجمالي الذكريات</div></div>
        <div class="rag-stat"><div class="rag-stat-num" style="color:#00c896">${approved}</div><div class="rag-stat-label">مصحّح من رمزي ✅</div></div>
        <div class="rag-stat"><div class="rag-stat-num" style="color:#7090dd">${autoSaved}</div><div class="rag-stat-label">تعلّم تلقائي 🤖</div></div>
        <div class="rag-stat"><div class="rag-stat-num">${uses}</div><div class="rag-stat-label">مرات الاستخدام</div></div>
        <div class="rag-stat"><div class="rag-stat-num">${d.aliases_count}</div><div class="rag-stat-label">أسماء عامية 🔤</div></div>
        <div class="rag-stat"><div class="rag-stat-num">${d.faults_count}</div><div class="rag-stat-label">أعطال محفوظة 🔧</div></div>
        <div class="rag-stat"><div class="rag-stat-num">${(avgScore*100).toFixed(0)}%</div><div class="rag-stat-label">متوسط الجودة</div></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
        <div class="mem-card">
          <div style="font-weight:700;margin-bottom:8px;font-size:0.85rem;">🔥 الأكثر استخداماً</div>
          ${(d.top_memories||[]).map(m => `
            <div style="border-bottom:1px solid var(--line-lt);padding:6px 0;font-size:0.8rem;">
              <div style="font-weight:600;margin-bottom:2px;">${esc(m.question.slice(0,50))}${m.question.length>50?'...':''}</div>
              <div style="color:var(--ink-dim);font-size:0.75rem;">${fmtInt(m.use_count)} استخدام · جودة ${(m.score*100).toFixed(0)}%</div>
            </div>
          `).join('') || '<div style="color:var(--ink-faint);font-size:0.82rem;">لا يوجد بعد</div>'}
        </div>
        <div class="mem-card">
          <div style="font-weight:700;margin-bottom:8px;font-size:0.85rem;">🆕 آخر ما تعلّمه</div>
          ${(d.recent_memories||[]).map(m => `
            <div style="border-bottom:1px solid var(--line-lt);padding:6px 0;font-size:0.8rem;">
              <div style="font-weight:600;margin-bottom:2px;">${esc(m.question.slice(0,50))}${m.question.length>50?'...':''}</div>
              <div style="color:var(--ink-dim);font-size:0.75rem;">${m.source === 'approved' ? '✅ رمزي' : '🤖 تلقائي'} · ${fmtDate(m.created_at)}</div>
            </div>
          `).join('') || '<div style="color:var(--ink-faint);font-size:0.82rem;">لا يوجد بعد</div>'}
        </div>
      </div>

      ${total < 5 ? `
        <div class="mem-card" style="border:1px solid var(--accent);background:rgba(100,150,255,.06);">
          <div style="font-weight:700;margin-bottom:6px;">💡 كيف تحسّن البوت؟</div>
          <ul style="margin:0;padding-right:18px;font-size:0.83rem;color:var(--ink-dim);line-height:1.8;">
            <li>اذهب لـ <b>تدريب</b> وأضف أسئلة وأجوبة شائعة</li>
            <li>كل ما زبون يوافق → البوت يتعلم تلقائياً</li>
            <li>البوت يصير "متوسط" بعد 20 ذكرى</li>
          </ul>
        </div>
      ` : ''}

      <div style="text-align:left;margin-top:10px;">
        <button class="btn btn-danger btn-sm" id="ragCleanupBtn">🗑 تنظيف الذكريات الضعيفة</button>
      </div>
    `;

    $('ragCleanupBtn')?.addEventListener('click', async () => {
      const btn = $('ragCleanupBtn');
      btn.disabled = true;
      btn.textContent = 'جارٍ التنظيف...';
      try {
        const r = await apiFetch('/rag.php', { method: 'POST', body: JSON.stringify({ action: 'cleanup' }) });
        showToast(r.message || `تم حذف ${r.deleted} ذكرى`, 'ok');
        renderRagOverview(c);
      } catch(e) { showToast(e.message || 'خطأ', 'error'); btn.disabled = false; btn.textContent = '🗑 تنظيف الذكريات الضعيفة'; }
    });

  } catch(e) {
    renderCenterState(c, { icon: 'alert', title: 'خطأ', text: e.message });
  }
}

// ── الذكريات ──────────────────────────────────────────────
async function renderRagMemories(c) {
  try {
    const params = new URLSearchParams({
      action: 'list',
      page: ragState.page,
      source: ragState.filterSource,
      q: ragState.filterQ,
    });
    const res   = await apiFetch('/rag.php?' + params);
    const items = res.data?.items || [];
    const total = res.data?.total || 0;
    ragState.pages = res.data?.pages || 1;

    c.innerHTML = `
      <div class="rag-search-bar">
        <input id="ragSearchInput" placeholder="ابحث بسؤال أو جواب..." value="${esc(ragState.filterQ)}">
        <select id="ragSourceFilter">
          <option value="">كل المصادر</option>
          <option value="approved" ${ragState.filterSource==='approved'?'selected':''}>✅ رمزي صحّح</option>
          <option value="auto" ${ragState.filterSource==='auto'?'selected':''}>🤖 تلقائي</option>
        </select>
        <button class="btn btn-ghost btn-sm" id="ragSearchBtn">${ICONS.search}</button>
      </div>
      <div style="font-size:0.8rem;color:var(--ink-dim);margin-bottom:10px;">${fmtInt(total)} ذكرى</div>
      <div id="ragMemList">
        ${items.length === 0 ? '<div style="text-align:center;padding:30px;color:var(--ink-faint);">لا توجد ذكريات بعد 🧠</div>' :
          items.map(m => `
            <div class="mem-card">
              <div class="mem-card-q">${esc(m.question)}</div>
              <div class="mem-card-a">${esc(m.answer.slice(0,120))}${m.answer.length>120?'...':''}</div>
              <div class="mem-meta">
                <span class="pill ${m.source==='approved'?'pill-approved':'pill-auto'}">${m.source==='approved'?'✅ رمزي':'🤖 تلقائي'}</span>
                <span class="pill pill-mute">جودة ${(parseFloat(m.score)*100).toFixed(0)}%</span>
                <span class="pill pill-mute">${fmtInt(m.use_count)} استخدام</span>
                ${m.tags ? `<span class="pill pill-mute">🏷 ${esc(m.tags)}</span>` : ''}
                <div style="margin-right:auto;display:flex;gap:6px;">
                  <button class="btn btn-ghost btn-sm" data-penalize="${m.id}" title="خفّض الجودة">👎</button>
                  <button class="btn btn-danger btn-sm" data-del="${m.id}">حذف</button>
                </div>
              </div>
            </div>
          `).join('')}
      </div>
      <div class="pager" style="margin-top:12px;" ${ragState.pages<=1?'hidden':''}>
        <button id="ragPrev" ${ragState.page<=1?'disabled':''}>${ICONS.chevR}</button>
        <span>${ragState.page} / ${ragState.pages}</span>
        <button id="ragNext" ${ragState.page>=ragState.pages?'disabled':''}>${ICONS.chevL}</button>
      </div>
    `;

    $('ragSearchBtn')?.addEventListener('click', () => {
      ragState.filterQ = $('ragSearchInput').value.trim();
      ragState.filterSource = $('ragSourceFilter').value;
      ragState.page = 1;
      renderRagMemories(c);
    });
    $('ragSearchInput')?.addEventListener('keydown', e => { if(e.key==='Enter') $('ragSearchBtn').click(); });

    $('ragPrev')?.addEventListener('click', () => { if(ragState.page>1){ ragState.page--; renderRagMemories(c); } });
    $('ragNext')?.addEventListener('click', () => { if(ragState.page<ragState.pages){ ragState.page++; renderRagMemories(c); } });

    c.querySelectorAll('[data-penalize]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await apiFetch('/rag.php', { method:'POST', body: JSON.stringify({ action:'penalize', id: parseInt(btn.dataset.penalize) }) });
        showToast('تم تخفيض الجودة', 'ok');
        renderRagMemories(c);
      });
    });

    c.querySelectorAll('[data-del]').forEach(btn => {
      btn.addEventListener('click', () => {
        confirmModal({ title:'حذف الذكرى', text:'متأكد؟', confirmLabel:'حذف', onConfirm: async () => {
          await apiFetch('/rag.php', { method:'DELETE', body: JSON.stringify({ action:'delete_memory', id: parseInt(btn.dataset.del) }) });
          showToast('تم الحذف', 'ok');
          renderRagMemories(c);
        }});
      });
    });

  } catch(e) { renderCenterState(c, { icon:'alert', title:'خطأ', text: e.message }); }
}

// ── تدريب ─────────────────────────────────────────────────
function renderRagTrain(c) {
  c.innerHTML = `
    <div class="train-form">
      <div style="font-weight:700;margin-bottom:12px;font-size:0.9rem;">🎓 أضف تدريب يدوي</div>
      <div>
        <label>السؤال أو الموقف</label>
        <textarea id="trainQ" placeholder="مثال: زبون سأل عن غسالة LG 7 كيلو ما بتعصر"></textarea>
      </div>
      <div>
        <label>الجواب الصح</label>
        <textarea id="trainA" placeholder="مثال: في الغالب المشكلة بالقشاط أو بالماتور، عندنا قشاط LG..."></textarea>
      </div>
      <div>
        <label>الوسوم (اختياري) — مفصولة بفاصلة</label>
        <input id="trainTags" placeholder="مثال: غسالة,LG,قشاط">
      </div>
      <button class="btn btn-primary" id="trainSaveBtn">${ICONS.check} احفظ كتدريب مؤكد</button>
    </div>

    <div class="mem-card" style="background:rgba(100,150,255,.06);border:1px solid rgba(100,150,255,.2);">
      <div style="font-weight:700;margin-bottom:8px;">💡 نصائح للتدريب الفعّال</div>
      <ul style="margin:0;padding-right:18px;font-size:0.82rem;color:var(--ink-dim);line-height:2;">
        <li>اكتب السؤال كما يقوله الزبون بالضبط</li>
        <li>الجواب المصحّح من رمزي له أولوية على التعلم التلقائي</li>
        <li>كلما أضفت أكثر، كلما صار البوت أذكى وأسرع</li>
        <li>ركّز على الأسئلة الشائعة: أعطال، أسعار، توفر قطع</li>
      </ul>
    </div>
  `;

  $('trainSaveBtn')?.addEventListener('click', async () => {
    const q = $('trainQ').value.trim();
    const a = $('trainA').value.trim();
    const tags = $('trainTags').value.trim() || null;
    if (!q || !a) { showToast('السؤال والجواب مطلوبان', 'error'); return; }
    const btn = $('trainSaveBtn');
    btn.disabled = true; btn.textContent = 'جارٍ الحفظ...';
    try {
      await apiFetch('/rag.php', { method:'POST', body: JSON.stringify({ action:'approve', question:q, answer:a, tags }) });
      showToast('تم الحفظ ✅ البوت تعلّم!', 'ok');
      $('trainQ').value = ''; $('trainA').value = ''; $('trainTags').value = '';
    } catch(e) { showToast(e.message || 'خطأ', 'error'); }
    btn.disabled = false; btn.innerHTML = `${ICONS.check} احفظ كتدريب مؤكد`;
  });
}

// ── أسماء عامية ───────────────────────────────────────────
async function renderRagAliases(c) {
  try {
    const res   = await apiFetch('/rag.php?action=aliases');
    const items = res.data || [];

    c.innerHTML = `
      <div class="train-form">
        <div style="font-weight:700;margin-bottom:10px;font-size:0.88rem;">➕ أضف اسم عامي جديد</div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px;">
          <div><label>الاسم العامي (ما يقوله الزبون)</label><input id="aliasInput" placeholder="مثال: كربون"></div>
          <div><label>الاسم الرسمي (بالمخزن)</label><input id="officialInput" placeholder="مثال: مروحة"></div>
          <div><label>الجهاز</label><input id="deviceInput" placeholder="مثال: غسالة"></div>
        </div>
        <button class="btn btn-primary btn-sm" id="addAliasBtn">${ICONS.plus} إضافة</button>
      </div>

      <div style="font-size:0.8rem;color:var(--ink-dim);margin-bottom:8px;">${items.length} اسم عامي محفوظ</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;">
        ${items.map(a => `
          <div class="mem-card" style="padding:10px;">
            <div style="font-weight:700;font-size:0.85rem;">"${esc(a.alias)}"</div>
            <div style="font-size:0.78rem;color:var(--ink-dim);">→ ${esc(a.official_name)}${a.device?' ('+esc(a.device)+')':''}</div>
            <button class="btn btn-danger btn-sm" style="margin-top:6px;font-size:0.72rem;" data-del="${a.id}">حذف</button>
          </div>
        `).join('')}
      </div>
    `;

    $('addAliasBtn')?.addEventListener('click', async () => {
      const alias    = $('aliasInput').value.trim();
      const official = $('officialInput').value.trim();
      const device   = $('deviceInput').value.trim() || null;
      if (!alias || !official) { showToast('الاسم العامي والرسمي مطلوبان', 'error'); return; }
      await apiFetch('/rag.php', { method:'POST', body: JSON.stringify({ action:'add_alias', alias, official_name:official, device }) });
      showToast('تمت الإضافة ✅', 'ok');
      renderRagAliases(c);
    });

    c.querySelectorAll('[data-del]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await apiFetch('/rag.php', { method:'DELETE', body: JSON.stringify({ action:'delete_alias', id: parseInt(btn.dataset.del) }) });
        showToast('تم الحذف', 'ok');
        renderRagAliases(c);
      });
    });

  } catch(e) { renderCenterState(c, { icon:'alert', title:'خطأ', text: e.message }); }
}

// ── الأعطال ───────────────────────────────────────────────
async function renderRagFaults(c) {
  try {
    const res   = await apiFetch('/rag.php?action=faults');
    const items = res.data || [];
    const devices = [...new Set(items.map(i => i.device))];
    let activeDevice = devices[0] || '';

    function renderFaultList() {
      const filtered = activeDevice ? items.filter(i => i.device === activeDevice) : items;
      return filtered.map(f => `
        <div class="mem-card" style="padding:10px;">
          <div style="font-weight:700;font-size:0.83rem;">${esc(f.symptom)}</div>
          <div style="font-size:0.78rem;color:var(--ink-dim);margin-top:3px;">🔍 ${esc(f.cause)}</div>
          ${f.part_needed ? `<div style="font-size:0.75rem;color:var(--accent);margin-top:2px;">🔧 ${esc(f.part_needed)}</div>` : ''}
          <button class="btn btn-danger btn-sm" style="margin-top:6px;font-size:0.72rem;" data-del="${f.id}">حذف</button>
        </div>
      `).join('');
    }

    c.innerHTML = `
      <div class="train-form">
        <div style="font-weight:700;margin-bottom:10px;font-size:0.88rem;">➕ أضف عطل شائع</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
          <div><label>الجهاز</label><input id="faultDevice" placeholder="مثال: غسالة"></div>
          <div><label>العرض (كما يقوله الزبون)</label><input id="faultSymptom" placeholder="مثال: ما بتعصر"></div>
          <div><label>السبب المحتمل</label><input id="faultCause" placeholder="مثال: قشاط ممزق أو ماتور"></div>
          <div><label>القطعة المطلوبة</label><input id="faultPart" placeholder="مثال: قشاط أو ماتور"></div>
        </div>
        <button class="btn btn-primary btn-sm" id="addFaultBtn">${ICONS.plus} إضافة</button>
      </div>

      <div class="devices-row" id="devicesRow">
        ${devices.map(d => `<span class="device-pill ${d===activeDevice?'active':''}" data-dev="${esc(d)}">${esc(d)}</span>`).join('')}
      </div>
      <div id="faultList">${renderFaultList()}</div>
    `;

    $('addFaultBtn')?.addEventListener('click', async () => {
      const device  = $('faultDevice').value.trim();
      const symptom = $('faultSymptom').value.trim();
      const cause   = $('faultCause').value.trim();
      const part    = $('faultPart').value.trim() || null;
      if (!device || !symptom || !cause) { showToast('الجهاز والعرض والسبب مطلوبة', 'error'); return; }
      await apiFetch('/rag.php', { method:'POST', body: JSON.stringify({ action:'add_fault', device, symptom, cause, part_needed:part }) });
      showToast('تمت الإضافة ✅', 'ok');
      renderRagFaults(c);
    });

    c.querySelectorAll('.device-pill').forEach(pill => {
      pill.addEventListener('click', () => {
        activeDevice = pill.dataset.dev;
        c.querySelectorAll('.device-pill').forEach(p => p.classList.toggle('active', p.dataset.dev === activeDevice));
        $('faultList').innerHTML = renderFaultList();
        wireFaultDelete();
      });
    });

    function wireFaultDelete() {
      c.querySelectorAll('[data-del]').forEach(btn => {
        btn.addEventListener('click', async () => {
          await apiFetch('/rag.php', { method:'DELETE', body: JSON.stringify({ action:'delete_fault', id: parseInt(btn.dataset.del) }) });
          showToast('تم الحذف', 'ok');
          renderRagFaults(c);
        });
      });
    }
    wireFaultDelete();

  } catch(e) { renderCenterState(c, { icon:'alert', title:'خطأ', text: e.message }); }
}

// ── اختبار ────────────────────────────────────────────────
function renderRagTest(c) {
  c.innerHTML = `
    <div class="train-form">
      <div style="font-weight:700;margin-bottom:10px;font-size:0.9rem;">🧪 اختبر ذاكرة البوت</div>
      <label>اكتب سؤال كما يكتبه الزبون</label>
      <input id="testInput" placeholder="مثال: كربون الغسالة عندكم؟ أو الثلاجة ما بتبرد...">
      <button class="btn btn-primary" id="testBtn" style="margin-top:8px;">${ICONS.search} اختبر</button>
    </div>
    <div id="testResult"></div>
  `;

  $('testBtn')?.addEventListener('click', async () => {
    const q = $('testInput').value.trim();
    if (!q) return;
    const btn = $('testBtn');
    btn.disabled = true; btn.textContent = 'جارٍ البحث...';
    const r = $('testResult');
    r.innerHTML = '';

    try {
      const res = await apiFetch('/rag.php?action=search&q=' + encodeURIComponent(q));
      const results = res.results || [];
      const alias   = res.resolved_alias || '';

      r.innerHTML = `
        <div class="test-result">
          ${alias && alias !== q ? `<div style="margin-bottom:10px;padding:8px 12px;background:rgba(100,150,255,.1);border-radius:8px;font-size:0.83rem;">🔤 حوّلت "<b>${esc(q)}</b>" إلى "<b>${esc(alias)}</b>"</div>` : ''}
          ${results.length === 0
            ? `<div style="text-align:center;padding:20px;color:var(--ink-faint);">🤷 ما لقيت ذكريات مشابهة<br><span style="font-size:0.78rem;">البوت سيجاوب من معلوماته العامة</span></div>`
            : results.map((m,i) => `
              <div style="margin-bottom:12px;border-bottom:1px solid var(--line-lt);padding-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                  <span class="pill ${m.source==='approved'?'pill-approved':'pill-auto'}">${m.source==='approved'?'✅ رمزي':'🤖 تلقائي'}</span>
                  <div class="score-bar">
                    <div class="score-fill" style="width:${(m.similarity*100).toFixed(0)}px;max-width:80px;"></div>
                    <span style="font-size:0.75rem;color:var(--ink-dim);">${(m.similarity*100).toFixed(0)}% تشابه</span>
                  </div>
                </div>
                <div style="font-weight:600;font-size:0.83rem;">س: ${esc(m.question)}</div>
                <div style="font-size:0.8rem;color:var(--ink-dim);margin-top:3px;">ج: ${esc(m.answer.slice(0,120))}${m.answer.length>120?'...':''}</div>
              </div>
            `).join('')
          }
        </div>
      `;
    } catch(e) { r.innerHTML = `<div class="test-result" style="color:var(--danger-lt);">خطأ: ${esc(e.message)}</div>`; }

    btn.disabled = false; btn.innerHTML = `${ICONS.search} اختبر`;
  });

  $('testInput')?.addEventListener('keydown', e => { if(e.key==='Enter') $('testBtn').click(); });
}

function fmtDate(str) {
  if (!str) return '—';
  return new Date(str).toLocaleDateString('ar-JO', { month:'short', day:'numeric' });
}
