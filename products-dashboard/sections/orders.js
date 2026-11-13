"use strict";
/* ============================================================
   قسم: الطلبات — كل طلبات الصيانة + تغيير الحالة (4 حالات)
   ============================================================ */

const ORDER_STATUSES = [
  { id: "pending",     label: "قيد الانتظار", pill: "pill-warn"   },
  { id: "in_progress", label: "قيد التنفيذ",  pill: "pill-blue"   },
  { id: "completed",   label: "مكتمل",        pill: "pill-ok"     },
  { id: "cancelled",   label: "ملغي",         pill: "pill-danger" },
];
const ordersState = { status: "all", q: "" };

VIEW_MODULES.orders = {
  title: "الطلبات",
  crumb: "كل طلبات الصيانة — شوفها وغيّر حالتها",
  render(container){
    container.innerHTML = `
      <div id="ordersSummary"></div>
      <div class="toolbar">
        <input id="ordersSearch" type="search" placeholder="ابحث: اسم / رقم هاتف / رقم طلب / جهاز"
          style="flex:1;min-width:200px;background:var(--panel);border:1px solid var(--line-lt);border-radius:10px;padding:9px 12px;color:var(--ink);font-family:var(--sans);font-size:0.85rem;">
      </div>
      <div id="ordersList"></div>
    `;
    $("ordersSearch").value = ordersState.q;
    $("ordersSearch").addEventListener("input", debounce((e) => { ordersState.q = e.target.value.trim(); loadOrders(); }, 350));
    loadOrders();
  },
  onRefresh(){ loadOrders(); }
};

async function loadOrders(){
  const list = $("ordersList");
  if(!list) return;
  renderSkeletonRows(list, 5);
  try{
    const qs = `status=${encodeURIComponent(ordersState.status)}&q=${encodeURIComponent(ordersState.q)}`;
    const body = await apiFetch(`/orders.php?${qs}`);
    renderOrdersSummary(body.summary || {});
    renderOrdersList(list, body.rows || []);
  }catch(err){
    renderCenterState(list, { icon:"alert", title:"صار خطأ", text: err.message || "تعذر تحميل الطلبات" });
  }
}

function renderOrdersSummary(s){
  const chip = (id, label, cls, n) => `
    <button class="stat-chip ${cls}" data-ostatus="${id}" style="cursor:pointer;${ordersState.status===id?'outline:2px solid var(--brand-lt);':''}">
      ${label}: <b>${fmtInt(n)}</b></button>`;
  $("ordersSummary").innerHTML = `
    <div class="stat-row" style="flex-wrap:wrap;gap:10px;">
      ${chip("all","الكل","",s.total)}
      ${chip("pending","قيد الانتظار","warn",s.pending)}
      ${chip("in_progress","قيد التنفيذ","",s.in_progress)}
      ${chip("completed","مكتمل","ok",s.completed)}
      ${chip("cancelled","ملغي","danger",s.cancelled)}
    </div>`;
  $("ordersSummary").querySelectorAll("[data-ostatus]").forEach(b => {
    b.addEventListener("click", () => { ordersState.status = b.dataset.ostatus; loadOrders(); });
  });
}

function renderOrdersList(list, rows){
  if(rows.length === 0){
    list.innerHTML = `<div class="center-state" style="padding:30px 16px;">${ICONS.search}<h3 style="font-size:0.9rem;">ما في طلبات</h3><p>جرّب فلتر ثاني أو بحث مختلف</p></div>`;
    return;
  }
  list.innerHTML = `<div style="display:flex;flex-direction:column;gap:10px;">${rows.map(orderCardHtml).join("")}</div>`;

  list.querySelectorAll("[data-set-status]").forEach(btn => {
    btn.addEventListener("click", () => {
      const id = parseInt(btn.dataset.id, 10);
      const status = btn.dataset.setStatus;
      const label = ORDER_STATUSES.find(s => s.id === status).label;
      const num = btn.dataset.num || id;
      confirmModal({
        title: "تغيير حالة الطلب",
        text: `تغيير حالة الطلب ${num} إلى "${label}"؟`,
        confirmLabel: "تأكيد",
        danger: status === "cancelled",
        onConfirm: async () => {
          await apiFetch("/orders.php", { method:"PATCH", body: JSON.stringify({ id, status }) });
          showToast("تم تحديث الحالة ✅", "success");
          loadOrders();
        }
      });
    });
  });
}

function orderCardHtml(r){
  const cur = ORDER_STATUSES.find(s => s.id === r.ui_status) || ORDER_STATUSES[0];
  const dev = [r.product_name, r.issue_description].filter(Boolean).map(esc).join(" — ");
  const btns = ORDER_STATUSES.map(s => `
    <button class="btn btn-sm ${s.id === r.ui_status ? 'btn-primary' : 'btn-ghost'}" ${s.id === r.ui_status ? 'disabled' : ''}
      data-set-status="${s.id}" data-id="${r.id}" data-num="${esc(r.order_number || '')}">${s.label}</button>`).join("");
  return `
    <div class="row-card" style="padding:14px;gap:10px;">
      <div class="row-card-top">
        <div style="min-width:0;">
          <div class="row-card-title">${esc(r.order_number || ('#' + r.id))} · ${esc(r.customer_name || 'بدون اسم')}</div>
          <div style="font-size:0.72rem;color:var(--ink-faint);margin-top:2px;">${esc(fmtDate(r.created_at))} · ${esc(timeAgo(r.created_at))}</div>
        </div>
        <span class="pill ${cur.pill}" style="flex-shrink:0;">${cur.label}</span>
      </div>
      <div style="font-size:0.82rem;color:var(--ink-dim);">🖥️ ${dev || 'غير محدد'}</div>
      <div style="font-size:0.8rem;color:var(--ink-dim);">📍 ${esc(r.customer_location || 'غير محدد')}</div>
      <div style="font-size:0.8rem;color:var(--ink-dim);">📱 <span dir="ltr">${esc(r.customer_phone || '')}</span></div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:4px;">${btns}
        <button class="btn btn-sm btn-ghost" data-open-conv="${r.conversation_id || ''}" data-phone="${esc(r.customer_phone||'')}">💬 المحادثة</button>
      </div>
    </div>`;
}
