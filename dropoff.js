"use strict";
/* ============================================================
   قسم: زباين تسرّبوا — سألوا عن صيانة وما أكدوا الطلب
   يعرض: كم زبون تردد، كم رجع بعد التذكير التلقائي، ونسبة التحويل
   ============================================================ */

const dropoffState = { status: "all", days: 30, channel: "" };

VIEW_MODULES.dropoff = {
  title: "زباين تسرّبوا",
  crumb: "سألوا عن صيانة ووصلوا لخطوة التأكيد بس ما أكدوا",
  render(container){
    container.innerHTML = `
      <div class="toolbar" style="flex-wrap:wrap;gap:8px;">
        <select id="dropChannel" style="background:var(--panel);border:1px solid var(--line-lt);border-radius:10px;padding:9px 12px;color:var(--ink);font-family:var(--sans);font-size:0.85rem;">
          <option value="">كل المنصات</option>
          <option value="whatsapp">🟢 واتساب</option>
          <option value="instagram">📸 انستغرام</option>
          <option value="messenger">🔵 فيسبوك</option>
        </select>
        <select id="dropStatus" style="background:var(--panel);border:1px solid var(--line-lt);border-radius:10px;padding:9px 12px;color:var(--ink);font-family:var(--sans);font-size:0.85rem;">
          <option value="all">الكل</option>
          <option value="pending">لسا معلّق</option>
          <option value="recovered">رجع وأكد</option>
        </select>
        <select id="dropDays" style="background:var(--panel);border:1px solid var(--line-lt);border-radius:10px;padding:9px 12px;color:var(--ink);font-family:var(--sans);font-size:0.85rem;">
          <option value="7">آخر 7 أيام</option>
          <option value="30" selected>آخر 30 يوم</option>
          <option value="90">آخر 90 يوم</option>
        </select>
      </div>
      <div id="dropoffContent"></div>
    `;
    $("dropChannel").addEventListener("change", (e) => { dropoffState.channel = e.target.value; loadDropoff(); });
    $("dropStatus").addEventListener("change", (e) => { dropoffState.status = e.target.value; loadDropoff(); });
    $("dropDays").addEventListener("change", (e) => { dropoffState.days = parseInt(e.target.value,10); loadDropoff(); });
    loadDropoff();
  },
  onRefresh(){ loadDropoff(); }
};

async function loadDropoff(){
  const content = $("dropoffContent");
  renderSkeletonRows(content, 6);
  try{
    const channelQS = dropoffState.channel ? `&channel=${dropoffState.channel}` : "";
    const body = await apiFetch(`/dropoff_report.php?status=${dropoffState.status}&days=${dropoffState.days}${channelQS}`);
    const s = body.summary || {};
    const rows = body.rows || [];

    let html = `
      <!-- الملخص -->
      <div class="row-card" style="align-items:center;text-align:center;padding:22px 18px;gap:6px;">
        <div class="stat-row" style="justify-content:center;flex-wrap:wrap;gap:10px;">
          <div class="stat-chip warn">معلّق: <b>${fmtInt(s.pending)}</b></div>
          <div class="stat-chip ok">رجع وأكد: <b>${fmtInt(s.recovered)}</b></div>
          <div class="stat-chip">الإجمالي: <b>${fmtInt(s.total)}</b></div>
          <div class="stat-chip ${s.conversion_rate >= 30 ? 'ok' : 'danger'}">نسبة الرجوع: <b>${s.conversion_rate || 0}%</b></div>
        </div>
      </div>

      <h3 style="font-size:0.92rem;margin:22px 0 10px;">📋 القائمة</h3>
      ${renderDropoffRows(rows)}
    `;

    content.innerHTML = html;
  }catch(err){
    renderCenterState(content, { icon:"alert", title:"صار خطأ", text: err.message || "تعذر تحميل التقرير" });
  }
}

function renderDropoffRows(rows){
  if(!rows || rows.length === 0){
    return `<div class="center-state" style="padding:30px 16px;">${ICONS.search}<h3 style="font-size:0.9rem;">ما في زباين متسربين بهاي الفترة</h3><p>هلق البوت شاطر 🎉</p></div>`;
  }
  return `<div style="display:flex;flex-direction:column;gap:8px;">
    ${rows.map(r => `
      <div class="row-card" style="padding:13px 14px;flex-direction:row;align-items:center;gap:12px;">
        <span class="pill ${r.recovered ? 'pill-ok' : 'pill-warn'}" style="flex-shrink:0;">${r.recovered ? '✅ رجع' : '⏳ معلّق'}</span>
        <div style="flex:1;min-width:0;">
          <div style="font-size:0.86rem;color:var(--ink);font-weight:700;">${channelEmoji(r.channel)} ${esc(r.customer_name || r.customer_phone)}</div>
          <div style="font-size:0.78rem;color:var(--ink-dim);margin-top:2px;">${esc(r.device_type || '')}${r.issue ? ' — ' + esc(r.issue) : ''}</div>
          <div style="font-size:0.72rem;color:var(--ink-faint);margin-top:2px;">📱 ${esc(r.customer_phone)} ${r.completed_orders_count > 0 ? `· 🌟 زبون سابق (${r.completed_orders_count})` : ''}</div>
        </div>
        <button data-open-conv="${r.conversation_id || ''}" data-phone="${esc(r.customer_phone||'')}"
           style="flex-shrink:0;background:var(--brand);color:#fff;padding:7px 12px;border-radius:8px;font-size:0.78rem;border:none;cursor:pointer;">تواصل</button>
      </div>
    `).join("")}
  </div>`;
}

// تفويض النقر على "تواصل": يفتح نفس المحادثة بقسم المحادثات بدل ما يودّي على واتساب مباشرة
document.addEventListener("click", (e) => {
  const btn = e.target.closest("[data-open-conv]");
  if (!btn) return;
  const convId = parseInt(btn.dataset.openConv, 10);
  if (convId) {
    navigate("conversations");
    if (typeof openThread === "function") openThread(convId);
  } else {
    // ما في محادثة مسجّلة (حالة نادرة) → افتح واتساب كحل بديل
    const phone = (btn.dataset.phone || "").replace(/[^0-9]/g, "");
    if (phone) window.open(`https://wa.me/${phone}`, "_blank");
  }
});
