"use strict";
/* ============================================================
   قسم: الموظفين — إضافة / تعديل / حذف
   ============================================================ */

VIEW_MODULES.employees = {
  title: "الموظفين",
  crumb: "إدارة حسابات الموظفين",
  render(container){
    container.innerHTML = `
      <div class="toolbar">
        <button class="btn btn-primary" id="addEmpBtn">${ICONS.plus} موظف جديد</button>
        ${diagnosisButtonHtml("diagEmpBtn")}
      </div>
      <div id="empContent"></div>
    `;
    $("addEmpBtn").addEventListener("click", () => openEmpModal(null));
    wireDiagnosisButton("diagEmpBtn", "/employees.php");
    loadEmployees();
  },
  onRefresh(){ loadEmployees(); }
};

async function loadEmployees(){
  const content = $("empContent");
  renderSkeletonRows(content, 5);
  try{
    const body = await apiFetch("/employees.php");
    const items = (body.data && body.data.items) || [];

    if(items.length === 0){
      renderCenterState(content, { icon:"users", title:"ما في موظفين بعد", text:"ضيف أول موظف من هون" });
      return;
    }

    content.innerHTML = `
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>الاسم</th><th>الهاتف</th><th>الدور</th><th>البوت</th><th>إجراءات</th></tr>
          </thead>
          <tbody>
            ${items.map(emp => `
              <tr data-id="${emp.id}">
                <td data-label="الاسم">${esc(emp.name)}</td>
                <td data-label="الهاتف" class="mono">${esc(emp.phone||'—')}</td>
                <td data-label="الدور"><span class="pill ${emp.role==='admin'?'pill-blue':'pill-mute'}">${emp.role==='admin'?'صاحب المتجر':'موظف'}</span></td>
                <td data-label="البوت">
                  <label class="switch">
                    <input type="checkbox" class="bot-toggle" data-id="${emp.id}" ${parseInt(emp.is_bot_active)?'checked':''}>
                    <span class="switch-track"></span>
                  </label>
                </td>
                <td class="actions">
                  <button class="btn btn-ghost btn-sm" data-edit="${emp.id}">${ICONS.edit}</button>
                  <button class="btn btn-danger btn-sm" data-del="${emp.id}">${ICONS.trash}</button>
                </td>
              </tr>
            `).join("")}
          </tbody>
        </table>
      </div>
    `;

    content.querySelectorAll(".bot-toggle").forEach(cb => {
      cb.addEventListener("change", async () => {
        cb.disabled = true;
        try{
          const r = await apiFetch("/employees.php", { method:"PATCH", body: JSON.stringify({ id: cb.dataset.id }) });
          showToast(r.message || "تم التحديث", "ok");
        }catch(err){
          showToast(err.message || "صار خطأ", "error");
          cb.checked = !cb.checked;
        }finally{ cb.disabled = false; }
      });
    });

    content.querySelectorAll("[data-edit]").forEach(btn => {
      btn.addEventListener("click", () => openEmpModal(items.find(i => String(i.id) === btn.dataset.edit)));
    });
    content.querySelectorAll("[data-del]").forEach(btn => {
      btn.addEventListener("click", () => {
        const emp = items.find(i => String(i.id) === btn.dataset.del);
        confirmModal({
          title: "حذف الموظف",
          text: `متأكد إنك بدك تحذف "${emp.name}"؟ هاد الإجراء ما إلو رجعة.`,
          confirmLabel: "حذف",
          onConfirm: async () => {
            await apiFetch("/employees.php", { method:"DELETE", body: JSON.stringify({ id: emp.id }) });
            showToast("تم حذف الموظف", "ok");
            loadEmployees();
          }
        });
      });
    });

  }catch(err){
    renderCenterState(content, { icon:"alert", title:"صار خطأ", text: err.message || "تعذر تحميل الموظفين" });
  }
}

function openEmpModal(emp){
  const isEdit = !!emp;
  openModal(`
    <div class="modal-head">
      <h3>${isEdit ? 'تعديل الموظف' : 'موظف جديد'}</h3>
      <button class="modal-close" id="mClose">${ICONS.x}</button>
    </div>
    <div class="modal-body">
      <div class="field">
        <label for="empName">الاسم</label>
        <input id="empName" type="text" value="${esc(emp?.name || '')}" placeholder="اسم الموظف"
          style="width:100%;background:rgba(0,0,0,0.22);border:1px solid var(--line-lt);border-radius:10px;padding:11px 13px;color:var(--ink);font-family:var(--sans);font-size:0.9rem;outline:none;">
      </div>
      <div class="field">
        <label for="empPhone">رقم الهاتف</label>
        <input id="empPhone" type="tel" value="${esc(emp?.phone || '')}" placeholder="07xxxxxxxx"
          style="width:100%;background:rgba(0,0,0,0.22);border:1px solid var(--line-lt);border-radius:10px;padding:11px 13px;color:var(--ink);font-family:var(--sans);font-size:0.9rem;outline:none;">
      </div>
      <div class="field">
        <label for="empRole">الدور</label>
        <select id="empRole">
          <option value="employee" ${emp?.role==='employee'||!emp?'selected':''}>موظف</option>
          <option value="admin" ${emp?.role==='admin'?'selected':''}>صاحب المتجر (admin)</option>
        </select>
      </div>
      ${!isEdit ? `
      <div class="field">
        <div class="row-card" style="margin:0;background:rgba(200,127,74,0.08);border-color:rgba(200,127,74,0.3);">
          <div class="row-card-body" style="display:flex;gap:9px;align-items:flex-start;color:var(--ink-dim);font-size:0.78rem;">
            <span style="color:var(--copper-lt);flex-shrink:0;">${ICONS.alert}</span>
            <span>هاد النظام ما بيسمح حالياً بتحديد كلمة سر مباشرة من هون. الموظف الجديد ما رح يقدر يسجّل دخول للوحة التحكم لحد ما تنضاف له كلمة سر يدوياً من قاعدة البيانات.</span>
          </div>
        </div>
      </div>` : ''}
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" id="mCancel">إلغاء</button>
      <button class="btn btn-primary" id="mSave">${ICONS.check} ${isEdit ? 'حفظ التعديل' : 'إضافة'}</button>
    </div>
  `);
  $("mClose").onclick = closeModal;
  $("mCancel").onclick = closeModal;
  $("mSave").onclick = async () => {
    const name = $("empName").value.trim();
    const phone = $("empPhone").value.trim();
    const role = $("empRole").value;
    if(!name){ showToast("الاسم مطلوب", "error"); return; }
    if(!phone){ showToast("رقم الهاتف مطلوب", "error"); return; }

    const btn = $("mSave");
    btn.disabled = true;
    btn.textContent = "جارٍ الحفظ...";
    try{
      if(isEdit){
        await apiFetch("/employees.php", { method:"PUT", body: JSON.stringify({ id: emp.id, name, phone, role }) });
      }else{
        await apiFetch("/employees.php", { method:"POST", body: JSON.stringify({ name, phone, role }) });
      }
      showToast(isEdit ? "تم التعديل" : "تم إضافة الموظف", "ok");
      closeModal();
      loadEmployees();
    }catch(err){
      showToast(err.message || "صار خطأ", "error");
      btn.disabled = false;
      btn.innerHTML = `${ICONS.check} ${isEdit ? 'حفظ التعديل' : 'إضافة'}`;
    }
  };
}
