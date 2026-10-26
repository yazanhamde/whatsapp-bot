"use strict";
/* ============================================================
   قسم: التقرير الشهري — عرض فقط
   يركّز على 3 أشياء:
   1. عدد طلبات الصيانة خلال الشهر
   2. أكثر الاستفسارات والطلبات (الأجهزة + الأعطال الشائعة)
   3. أكثر القطع التي سُئل عنها (واحتمال عدم توفرها بالمخزون)
   ============================================================ */

const reportState = { month: new Date().getMonth()+1, year: new Date().getFullYear(), channel: "" };
const AR_MONTHS_R = ["", "يناير","فبراير","مارس","أبريل","مايو","يونيو","يوليو","أغسطس","سبتمبر","أكتوبر","نوفمبر","ديسمبر"];
const REPORT_CHANNELS = [
  { key: "",           label: "كل المنصات", emoji: "💬" },
  { key: "whatsapp",   label: "واتساب",     emoji: "🟢" },
  { key: "instagram",  label: "انستغرام",   emoji: "📸" },
  { key: "messenger",  label: "فيسبوك",     emoji: "🔵" },
];

VIEW_MODULES.report = {
  title: "التقرير الشهري",
  crumb: "طلبات الصيانة · أكثر الاستفسارات · القطع المطلوبة",
  render(container){
    container.innerHTML = `
      <div class="toolbar" id="repChannelTabs" style="gap:8px;flex-wrap:wrap;"></div>
      <div class="toolbar">
        <select id="repMonth" style="background:var(--panel);border:1px solid var(--line-lt);border-radius:10px;padding:9px 12px;color:var(--ink);font-family:var(--sans);font-size:0.85rem;">
          ${AR_MONTHS_R.slice(1).map((m,i)=>`<option value="${i+1}" ${i+1===reportState.month?'selected':''}>${m}</option>`).join("")}
        </select>
        <select id="repYear" style="background:var(--panel);border:1px solid var(--line-lt);border-radius:10px;padding:9px 12px;color:var(--ink);font-family:var(--sans);font-size:0.85rem;">
          ${[reportState.year-1, reportState.year, reportState.year+1].map(y=>`<option value="${y}" ${y===reportState.year?'selected':''}>${y}</option>`).join("")}
        </select>
        ${diagnosisButtonHtml("diagReportBtn")}
      </div>
      <div id="reportContent"></div>
    `;
    renderReportChannelTabs();
    $("repMonth").addEventListener("change", (e) => { reportState.month = parseInt(e.target.value,10); loadReport(); });
    $("repYear").addEventListener("change", (e) => { reportState.year = parseInt(e.target.value,10); loadReport(); });
    loadReport();
  },
  onRefresh(){ loadReport(); }
};

function renderReportChannelTabs(){
  const box = $("repChannelTabs");
  if(!box) return;
  box.innerHTML = REPORT_CHANNELS.map(ch => `
    <button data-report-channel="${ch.key}" class="btn btn-sm ${reportState.channel === ch.key ? 'btn-primary' : 'btn-ghost'}">
      ${ch.emoji} ${ch.label}
    </button>
  `).join("");
  box.querySelectorAll("[data-report-channel]").forEach(btn => {
    btn.addEventListener("click", () => {
      reportState.channel = btn.dataset.reportChannel;
      renderReportChannelTabs();
      loadReport();
    });
  });
}

async function loadReport(){
  const content = $("reportContent");
  renderSkeletonRows(content, 6);
  const channelQS = reportState.channel ? `&channel=${reportState.channel}` : "";
  wireDiagnosisButton("diagReportBtn", `/monthly_report.php?month=${reportState.month}&year=${reportState.year}${channelQS}`);
  try{
    const body = await apiFetch(`/monthly_report.php?month=${reportState.month}&year=${reportState.year}${channelQS}`);
    const d = body.data || {};
    const m = d.maintenance || {};
    const breakdown = d.device_breakdown || [];
    const chLabel = REPORT_CHANNELS.find(c => c.key === reportState.channel)?.label || "";

    let html = `
      ${renderCustomersSummary(d.customers, reportState.channel)}

      <!-- 1) عدد طلبات الصيانة -->
      <div class="row-card" style="align-items:center;text-align:center;padding:26px 18px;gap:6px;">
        <div style="font-size:2.4rem;font-weight:800;font-family:var(--mono);color:var(--brand-lt);line-height:1;">${fmtInt(m.total)}</div>
        <div style="color:var(--ink-dim);font-size:0.85rem;">طلب صيانة${chLabel ? " عبر " + chLabel : ""} خلال ${AR_MONTHS_R[reportState.month]} ${reportState.year}</div>
        <div class="stat-row" style="justify-content:center;margin-top:10px;">
          <div class="stat-chip warn">قيد الانتظار: <b>${fmtInt(m.pending)}</b></div>
          <div class="stat-chip">قيد التنفيذ: <b>${fmtInt(m.in_progress)}</b></div>
          <div class="stat-chip ok">مكتمل: <b>${fmtInt(m.completed)}</b></div>
          <div class="stat-chip danger">ملغي: <b>${fmtInt(m.cancelled)}</b></div>
        </div>
      </div>

      <!-- 2) الأعطال حسب كل جهاز -->
      <h3 style="font-size:0.92rem;margin:24px 0 4px;">📋 الأعطال حسب نوع الجهاز</h3>
      <p style="font-size:0.76rem;color:var(--ink-faint);margin:0 0 12px;">كل جهاز وتحته أنواع الأعطال يلي صارت فيه هاد الشهر</p>
      ${renderDeviceBreakdown(breakdown)}

      <!-- 3) أكثر القطع المطلوبة -->
      <h3 style="font-size:0.92rem;margin:26px 0 4px;">🔧 أكثر القطع التي سُئل عنها</h3>
      <p style="font-size:0.76rem;color:var(--ink-faint);margin:0 0 12px;">قطع استفسر عنها الزباين${chLabel ? " على " + chLabel : ""} — راجعي توفرها بقسم «المنتجات»</p>
      ${renderPartsList(d.top_parts)}
    `;

    content.innerHTML = html;
    wireDeviceAccordions();

  }catch(err){
    renderCenterState(content, { icon:"alert", title:"صار خطأ", text: err.message || "تعذر تحميل التقرير" });
  }
}

function renderCustomersSummary(customers, channel){
  if(!customers) return "";

  if(channel){
    // منصة محددة: كرت واحد بعدد زباينها الكلي والجداد هاد الشهر
    return `
      <div class="stat-row" style="margin:0 0 16px;">
        <div class="stat-chip ok">👥 إجمالي زباين هاي المنصة: <b>${fmtInt(customers.total)}</b></div>
        <div class="stat-chip warn">🆕 زباين جداد هاد الشهر: <b>${fmtInt(customers.new_this_month)}</b></div>
      </div>
    `;
  }

  // كل المنصات: صف مقارنة سريع بين واتساب / انستغرام / فيسبوك
  const byChannel = customers.by_channel || [];
  if(byChannel.length === 0) return "";
  const chMeta = { whatsapp: {label:"واتساب", emoji:"🟢"}, instagram:{label:"انستغرام", emoji:"📸"}, messenger:{label:"فيسبوك", emoji:"🔵"} };
  return `
    <h3 style="font-size:0.92rem;margin:0 0 4px;">👥 الزباين حسب المنصة</h3>
    <p style="font-size:0.76rem;color:var(--ink-faint);margin:0 0 10px;">إجمالي الزباين، والجداد يلي انضموا هاد الشهر، لكل منصة على حدة</p>
    <div class="stat-row" style="margin:0 0 16px;">
      ${byChannel.map(c => {
        const meta = chMeta[c.channel] || { label:c.channel, emoji:"💬" };
        return `<div class="stat-chip">${meta.emoji} ${meta.label} — إجمالي: <b>${fmtInt(c.total)}</b> · جداد: <b>${fmtInt(c.new_this_month)}</b></div>`;
      }).join("")}
    </div>
  `;
}

function renderDeviceBreakdown(devices){
  if(!devices || devices.length === 0){
    return `<p style="color:var(--ink-faint);font-size:0.82rem;">لا يوجد بيانات لهاد الشهر</p>`;
  }
  return `<div style="display:flex;flex-direction:column;gap:9px;">
    ${devices.map((dev, idx) => {
      const hasIssues = dev.issues && dev.issues.length > 0;
      return `
      <div class="row-card" style="padding:0;overflow:hidden;">
        <button class="device-acc-head" data-acc="${idx}" style="width:100%;display:flex;align-items:center;gap:10px;padding:13px 14px;background:transparent;border:none;cursor:${hasIssues?'pointer':'default'};text-align:right;">
          <span style="font-size:1.1rem;flex-shrink:0;">${dev.icon || '📦'}</span>
          <span style="flex:1;font-weight:700;font-size:0.88rem;color:var(--ink);">${esc(dev.device)}</span>
          <span class="pill ${dev.total>0?'pill-warn':'pill-mute'}">${fmtInt(dev.total)} طلب</span>
          ${hasIssues ? `<span class="acc-chevron" style="color:var(--ink-faint);display:flex;transition:transform .15s;">${ICONS.chevL}</span>` : ''}
        </button>
        ${hasIssues ? `
        <div class="device-acc-body" data-acc-body="${idx}" hidden style="padding:0 14px 13px;border-top:1px solid var(--line);">
          <div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;">
            ${dev.issues.map((iss, i) => `
              <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:rgba(0,0,0,0.15);border-radius:8px;">
                <span class="mono" style="color:var(--copper-lt);font-weight:800;font-size:0.74rem;min-width:16px;">#${i+1}</span>
                <span style="flex:1;font-size:0.82rem;color:var(--ink-dim);">${esc(iss.label)}</span>
                <span class="pill pill-warn">${fmtInt(iss.count)}</span>
              </div>
            `).join("")}
          </div>
        </div>` : ''}
      </div>`;
    }).join("")}
  </div>`;
}

function wireDeviceAccordions(){
  document.querySelectorAll(".device-acc-head[data-acc]").forEach(btn => {
    const idx = btn.dataset.acc;
    const body = document.querySelector(`[data-acc-body="${idx}"]`);
    if(!body) return; // جهاز بدون أعطال — ما إله accordion
    btn.addEventListener("click", () => {
      const isOpen = !body.hidden;
      body.hidden = isOpen;
      const chevron = btn.querySelector(".acc-chevron");
      if(chevron) chevron.style.transform = isOpen ? "" : "rotate(-90deg)";
    });
  });
}

function renderPartsList(parts){
  if(!parts || parts.length === 0){
    return `<div class="center-state" style="padding:30px 16px;">${ICONS.search}<h3 style="font-size:0.9rem;">ما في استفسارات عن قطع هاد الشهر</h3><p>لما يسأل زبون عن قطعة معينة عالبوت، رح تظهر هون</p></div>`;
  }
  return `<div style="display:flex;flex-direction:column;gap:8px;">
    ${parts.map((p,i) => `
      <div class="row-card" style="padding:11px 14px;flex-direction:row;align-items:center;gap:12px;">
        <span class="mono" style="color:var(--copper-lt);font-weight:800;font-size:0.78rem;min-width:18px;">#${i+1}</span>
        <span style="flex:1;font-size:0.85rem;color:var(--ink);">${esc(p.part || '')}</span>
        <span class="pill pill-warn">${fmtInt(p.count||0)} استفسار</span>
      </div>
    `).join("")}
  </div>`;
}
