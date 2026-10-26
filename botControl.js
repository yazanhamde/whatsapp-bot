"use strict";
/* ============================================================
   قسم: التحكم بالبوت — لكل منصة على حدة (واتساب / انستغرام / فيسبوك)
   ============================================================ */

const BOT_CHANNELS = [
  { key: "whatsapp",  label: "واتساب",   emoji: "🟢", desc: "البوت بيرد تلقائياً على رسائل الزباين على واتساب." },
  { key: "instagram", label: "انستغرام", emoji: "📸", desc: "البوت بيرد تلقائياً على رسائل الزباين على انستغرام." },
  { key: "messenger", label: "فيسبوك",   emoji: "🔵", desc: "البوت بيرد تلقائياً على رسائل الزباين على ماسنجر فيسبوك." },
];

const botControlState = { statuses: {} }; // channel key => bool

VIEW_MODULES.botControl = {
  title: "التحكم بالبوت",
  crumb: "تشغيل أو إيقاف البوت لكل منصة على حدة",
  render(container){
    container.innerHTML = `<div id="botControlContent"></div>`;
    loadAllBotStatuses();
  },
  onRefresh(){ loadAllBotStatuses(); }
};

async function loadAllBotStatuses(){
  const content = $("botControlContent");
  content.innerHTML = `<div class="center-state"><div class="spinner"></div><p>جارٍ التحقق من حالة البوت</p></div>`;
  try{
    const results = await Promise.all(BOT_CHANNELS.map(ch =>
      fetch(CFG.API_BASE + "/toggle_global_bot.php?channel=" + ch.key, {
        headers: { "Authorization": "Bearer " + state.token }
      }).then(r => r.json()).catch(() => ({ bot_is_active: true }))
    ));
    BOT_CHANNELS.forEach((ch, i) => { botControlState.statuses[ch.key] = !!results[i].bot_is_active; });
    renderBotControl();
  }catch(err){
    renderCenterState(content, { icon:"alert", title:"صار خطأ", text:"تعذر التحقق من حالة البوت" });
  }
}

function renderBotControl(){
  const content = $("botControlContent");
  content.innerHTML = BOT_CHANNELS.map(ch => {
    const isActive = !!botControlState.statuses[ch.key];
    return `
    <div class="row-card" style="align-items:center;text-align:center;padding:26px 20px;gap:14px;margin-bottom:14px;">
      <div style="display:flex;align-items:center;gap:8px;font-size:0.8rem;color:var(--ink-faint);font-weight:700;">
        <span style="font-size:1.1rem;">${ch.emoji}</span> ${ch.label}
      </div>
      <div style="width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;
        background:${isActive ? 'rgba(95,174,126,0.15)' : 'rgba(194,86,74,0.15)'};color:${isActive?'var(--ok)':'var(--danger-lt)'};">
        ${ICONS.power}
      </div>
      <div>
        <h3 style="margin:0 0 4px;font-size:1rem;">${ch.label} حالياً ${isActive ? 'شغّال 🟢' : 'موقوف 🔴'}</h3>
        <p style="margin:0;color:var(--ink-faint);font-size:0.82rem;max-width:320px;">
          ${isActive ? ch.desc : `البوت موقوف كلياً على ${ch.label} — ما في ردود تلقائية لحد ما تشغله من جديد.`}
        </p>
      </div>
      <button class="btn ${isActive ? 'btn-danger' : 'btn-primary'}" data-bot-toggle="${ch.key}" style="padding:12px 24px;">
        ${isActive ? ICONS.pause + ' إيقاف ' + ch.label : ICONS.play + ' تشغيل ' + ch.label}
      </button>
    </div>`;
  }).join("") + `
    <div class="row-card">
      <div class="row-card-body" style="display:flex;gap:10px;align-items:flex-start;">
        <span style="color:var(--copper-lt);flex-shrink:0;margin-top:2px;">${ICONS.alert}</span>
        <span>إيقاف البوت عن منصة معيّنة بوقف الردود التلقائية فيها بس — باقي المنصات بتضل تشتغل عادي.</span>
      </div>
    </div>
  `;

  content.querySelectorAll("[data-bot-toggle]").forEach(btn => {
    const chKey = btn.dataset.botToggle;
    const ch = BOT_CHANNELS.find(c => c.key === chKey);
    btn.addEventListener("click", () => {
      const isActive = !!botControlState.statuses[chKey];
      if(isActive){
        confirmModal({
          title: `إيقاف البوت على ${ch.label}`,
          text: `متأكد إنك بدك توقف البوت عن كل الزباين على ${ch.label}؟ ما حدا رح ياخد رد تلقائي لحد ما تشغله من جديد.`,
          confirmLabel: "إيقاف البوت",
          onConfirm: () => toggleChannelBot(chKey, 'off')
        });
      } else {
        toggleChannelBot(chKey, 'on');
      }
    });
  });
}

async function toggleChannelBot(channel, status){
  try{
    const res = await fetch(CFG.API_BASE + "/toggle_global_bot.php?channel=" + channel, {
      method: "POST",
      headers: { "Authorization": "Bearer " + state.token, "Content-Type": "application/json" },
      body: JSON.stringify({ status, channel })
    });
    const body = await res.json();
    if(body.status !== 'success'){ throw new Error(body.message || 'صار خطأ'); }
    const ch = BOT_CHANNELS.find(c => c.key === channel);
    showToast(status === 'on' ? `تم تشغيل البوت على ${ch.label}` : `تم إيقاف البوت على ${ch.label}`, 'ok');
    loadAllBotStatuses();
  }catch(err){
    showToast(err.message || 'تعذر تحديث حالة البوت', 'error');
  }
}
