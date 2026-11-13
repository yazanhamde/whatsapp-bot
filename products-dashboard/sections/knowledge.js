"use strict";
/* ============================================================
   قسم: قاعدة المعرفة — إضافة / تعديل / حذف أسئلة وأجوبة
   ============================================================ */

const kbState = { page: 1, pages: 1, total: 0 };

VIEW_MODULES.knowledge = {
  title: "قاعدة المعرفة",
  crumb: "الأسئلة والأجوبة الجاهزة للبوت",
  render(container){
    container.innerHTML = `
      <div class="toolbar">
        <button class="btn btn-primary" id="addKbBtn">${ICONS.plus} سؤال جديد</button>
        <button class="btn btn-danger" id="clearAllKbBtn" style="margin-right:auto;">${ICONS.trash} حذف الكل</button>
        <button class="btn btn-ghost btn-sm" id="diagKbBtn">🩺 تشخيص</button>
      </div>
      <div id="kbContent"></div>
      <div class="pager" id="kbPager" hidden>
        <button id="kbPrev">${ICONS.chevR}</button>
        <span id="kbPageLabel">1 / 1</span>
        <button id="kbNext">${ICONS.chevL}</button>
      </div>
    `;
    $("addKbBtn").addEventListener("click", () => openKbModal(null));
    $("clearAllKbBtn").addEventListener("click", () => openClearAllModal());
    $("kbPrev").addEventListener("click", () => { if(kbState.page>1){ kbState.page--; loadKb(); } });
    $("kbNext").addEventListener("click", () => { if(kbState.page<kbState.pages){ kbState.page++; loadKb(); } });
    wireDiagnosisButton("diagKbBtn", "/knowledge-base.php?page=1");
    loadKb();
  },
  onRefresh(){ loadKb(); }
};

async function loadKb(){
  const content = $("kbContent");
  if(!content) return;
  renderSkeletonRows(content, 5);
  try{
    const body = await apiFetch(`/knowledge-base.php?page=${kbState.page}`);
    const data = body.data || {};
    const items = data.items || [];
    kbState.pages = data.pages || 1;
    kbState.total = data.total || 0;

    if(items.length === 0){
      renderCenterState(content, { icon:"brain", title:"ما في أسئلة بعد", text:"ضيف أول سؤال وجواب عشان البوت يبلش يتعلم" });
      $("kbPager").hidden = true;
      return;
    }

    content.innerHTML = items.map(kb => `
      <div class="row-card">
        <div class="row-card-top">
          <div style="flex:1;">
            <div class="row-card-title">${esc(kb.question)}</div>
          </div>
          ${kb.is_active == 0 ? '<span class="pill pill-mute">معطّل</span>' : ''}
        </div>
        <div class="row-card-body">${esc(kb.answer)}</div>
        <div class="row-card-sub">استُخدم ${fmtInt(kb.use_count||0)} مرة · المصدر: ${esc(kb.source||'—')}</div>
        <div class="row-card-actions">
          <button class="btn btn-ghost btn-sm" data-edit="${kb.id}">${ICONS.edit} تعديل</button>
          <button class="btn btn-danger btn-sm" data-del="${kb.id}">${ICONS.trash} حذف</button>
        </div>
      </div>
    `).join("");

    content.querySelectorAll("[data-edit]").forEach(btn => {
      btn.addEventListener("click", () => openKbModal(items.find(i => String(i.id) === btn.dataset.edit)));
    });
    content.querySelectorAll("[data-del]").forEach(btn => {
      btn.addEventListener("click", () => {
        const kb = items.find(i => String(i.id) === btn.dataset.del);
        confirmModal({
          title: "حذف السؤال",
          text: `متأكد إنك بدك تحذف "${(kb.question||'').slice(0,60)}"؟`,
          confirmLabel: "حذف",
          onConfirm: async () => {
            await apiFetch("/knowledge-base.php", { method:"DELETE", body: JSON.stringify({ id: kb.id }) });
            showToast("تم الحذف", "ok");
            loadKb();
          }
        });
      });
    });

    if(kbState.pages > 1){
      $("kbPager").hidden = false;
      $("kbPageLabel").textContent = `${fmtInt(kbState.page)} / ${fmtInt(kbState.pages)}`;
      $("kbPrev").disabled = kbState.page <= 1;
      $("kbNext").disabled = kbState.page >= kbState.pages;
    } else {
      $("kbPager").hidden = true;
    }

  }catch(err){
    renderCenterState(content, { icon:"alert", title:"صار خطأ", text: err.message || "تعذر تحميل قاعدة المعرفة" });
  }
}

function openClearAllModal(){
  const total = kbState.total || 0;
  openModal(`
    <div class="modal-head">
      <h3>حذف كل قاعدة المعرفة</h3>
      <button class="modal-close" id="mClose">${ICONS.x}</button>
    </div>
    <div class="modal-body">
      <div class="confirm-icon">${ICONS.alert}</div>
      <p class="confirm-text">
        رح يتحذف <b style="color:var(--ink);">${fmtInt(total)}</b> سؤال وجواب نهائياً من قاعدة المعرفة.<br><br>
        <b style="color:var(--danger-lt);">هاد الإجراء ما إله رجعة — البوت رح يفقد كل الأسئلة الجاهزة.</b>
      </p>
      <div class="field" style="margin-top:14px;">
        <label for="clearConfirmInput">اكتبي كلمة <span class="mono" style="color:var(--danger-lt);">حذف</span> للتأكيد</label>
        <input id="clearConfirmInput" type="text" autocomplete="off"
          style="width:100%;background:rgba(0,0,0,0.22);border:1px solid var(--line-lt);border-radius:10px;padding:11px 13px;color:var(--ink);font-family:var(--sans);font-size:0.9rem;outline:none;">
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" id="mCancel">إلغاء</button>
      <button class="btn btn-danger" id="mConfirmClear" disabled>${ICONS.trash} حذف الكل نهائياً</button>
    </div>
  `);
  $("mClose").onclick = closeModal;
  $("mCancel").onclick = closeModal;

  const input = $("clearConfirmInput");
  const confirmBtn = $("mConfirmClear");
  input.addEventListener("input", () => {
    confirmBtn.disabled = input.value.trim() !== "حذف";
  });
  input.focus();

  confirmBtn.onclick = async () => {
    if(input.value.trim() !== "حذف") return;
    confirmBtn.disabled = true;
    confirmBtn.textContent = "جارٍ الحذف...";
    try{
      await apiFetch("/knowledge-base.php?action=clear", { method: "DELETE", body: JSON.stringify({}) });
      showToast("تم حذف قاعدة المعرفة بالكامل", "ok");
      closeModal();
      kbState.page = 1;
      loadKb();
    }catch(err){
      showToast(err.message || "تعذر الحذف", "error");
      confirmBtn.disabled = false;
      confirmBtn.innerHTML = `${ICONS.trash} حذف الكل نهائياً`;
    }
  };
}

function openKbModal(kb){
  const isEdit = !!kb;
  openModal(`
    <div class="modal-head">
      <h3>${isEdit ? 'تعديل السؤال' : 'سؤال جديد'}</h3>
      <button class="modal-close" id="mClose">${ICONS.x}</button>
    </div>
    <div class="modal-body">
      <div class="field">
        <label for="kbQuestion">السؤال</label>
        <textarea id="kbQuestion" placeholder="مثال: شو ساعات الدوام؟" style="min-height:60px;">${esc(kb?.question || '')}</textarea>
      </div>
      <div class="field">
        <label for="kbAnswer">الجواب</label>
        <textarea id="kbAnswer" placeholder="اكتب الجواب الجاهز هون...">${esc(kb?.answer || '')}</textarea>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" id="mCancel">إلغاء</button>
      <button class="btn btn-primary" id="mSave">${ICONS.check} ${isEdit ? 'حفظ التعديل' : 'إضافة'}</button>
    </div>
  `);
  $("mClose").onclick = closeModal;
  $("mCancel").onclick = closeModal;
  $("mSave").onclick = async () => {
    const question = $("kbQuestion").value.trim();
    const answer = $("kbAnswer").value.trim();
    if(!question){ showToast("السؤال مطلوب", "error"); return; }
    if(!answer){ showToast("الجواب مطلوب", "error"); return; }

    const btn = $("mSave");
    btn.disabled = true;
    btn.textContent = "جارٍ الحفظ...";
    try{
      if(isEdit){
        await apiFetch("/knowledge-base.php", { method:"PUT", body: JSON.stringify({ id: kb.id, question, answer }) });
      }else{
        await apiFetch("/knowledge-base.php", { method:"POST", body: JSON.stringify({ question, answer }) });
      }
      showToast(isEdit ? "تم التعديل" : "تم الإضافة", "ok");
      closeModal();
      loadKb();
    }catch(err){
      showToast(err.message || "صار خطأ", "error");
      btn.disabled = false;
      btn.innerHTML = `${ICONS.check} ${isEdit ? 'حفظ التعديل' : 'إضافة'}`;
    }
  };
}
