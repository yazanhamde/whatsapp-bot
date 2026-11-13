"use strict";
/* ============================================================
   قسم: التحكم بالبوت العام — تشغيل / إيقاف كلياً
   ============================================================ */

VIEW_MODULES.botControl = {
  title: "التحكم بالبوت",
  crumb: "تشغيل أو إيقاف البوت كلياً",
  render(container){
    container.innerHTML = `<div id="botControlContent"></div>`;
    loadBotStatus();
  },
  onRefresh(){ loadBotStatus(); }
};

let _botCurrentStatus = null;
let _botToggling      = false;
let _botLoading       = false; // ✅ منع تعدد استدعاءات loadBotStatus في نفس الوقت

async function loadBotStatus(){
  // ✅ إذا في تحميل جاري بالفعل — لا تبدأ ثاني
  if(_botLoading) return;
  _botLoading = true;

  const content = $("botControlContent");
  if(!content){ _botLoading = false; return; } // ✅ إذا المستخدم غادر الصفحة

  content.innerHTML = `<div class="center-state"><div class="spinner"></div><p>جارٍ التحقق من حالة البوت...</p></div>`;

  try{
    const body = await apiFetch("/toggle_global_bot.php");

    if(typeof body.bot_is_active !== 'boolean'){
      throw new Error("استجابة غير متوقعة: " + JSON.stringify(body));
    }

    _botCurrentStatus = body.bot_is_active;
    state.botActive   = body.bot_is_active;

    // ✅ تحقق مرة ثانية إن المستخدم لسا بنفس الصفحة قبل ما نرسم
    const c2 = $("botControlContent");
    if(c2) renderBotControl(body.bot_is_active);

  }catch(err){
    const c = $("botControlContent");
    if(c) renderCenterState(c, {
      icon: "alert",
      title: "تعذر تحميل حالة البوت",
      text: err.message || "خطأ غير معروف"
    });
  }finally{
    _botLoading = false; // ✅ دايماً نفتح الباب بعد انتهاء التحميل
  }
}

function renderBotControl(isActive){
  const content = $("botControlContent");
  if(!content) return;

  // ✅ نمسح المحتوى القديم كاملاً ثم نرسم من جديد
  content.innerHTML = `
    <div class="row-card" style="align-items:center;text-align:center;padding:32px 20px;gap:18px;">
      <div style="width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;
        background:${isActive ? 'rgba(95,174,126,0.15)' : 'rgba(194,86,74,0.15)'};
        color:${isActive ? 'var(--ok)' : 'var(--danger-lt)'};">
        ${ICONS.power}
      </div>
      <div>
        <h3 style="margin:0 0 4px;font-size:1.05rem;">
          البوت حالياً ${isActive ? 'شغّال 🟢' : 'موقوف 🔴'}
        </h3>
        <p style="margin:0;color:var(--ink-faint);font-size:0.84rem;max-width:320px;">
          ${isActive
            ? 'البوت بيرد تلقائياً على كل رسائل الزباين على واتساب.'
            : 'البوت موقوف كلياً — ما في ردود تلقائية لحد ما تشغله من جديد.'}
        </p>
      </div>
      <button class="btn ${isActive ? 'btn-danger' : 'btn-primary'}"
              id="toggleGlobalBotBtn"
              style="padding:13px 26px;"
              data-intent="${isActive ? 'off' : 'on'}">
        ${isActive ? ICONS.pause + ' إيقاف البوت كلياً' : ICONS.play + ' تشغيل البوت'}
      </button>
    </div>
    <div class="row-card" style="margin-top:14px;">
      <div class="row-card-body" style="display:flex;gap:10px;align-items:flex-start;">
        <span style="color:var(--copper-lt);flex-shrink:0;margin-top:2px;">${ICONS.alert}</span>
        <span>إيقاف البوت كلياً بوقف الردود التلقائية لكل الزباين على واتساب — استخدمه بس لما يكون في داعي فعلي (صيانة، طوارئ...).</span>
      </div>
    </div>
    <div class="toolbar" style="margin-top:14px;">
      ${diagnosisButtonHtml("diagBotBtn")}
    </div>
  `;

  wireDiagnosisButton("diagBotBtn", "/toggle_global_bot.php");

  const btn = $("toggleGlobalBotBtn");
  if(!btn) return;

  btn.addEventListener("click", () => {
    const intent = btn.dataset?.intent;
    if(!intent) return;

    if(intent === 'off'){
      confirmModal({
        title: "إيقاف البوت كلياً",
        text: "متأكد إنك بدك توقف البوت عن كل الزباين؟ ما حدا رح ياخد رد تلقائي لحد ما تشغله من جديد.",
        confirmLabel: "إيقاف البوت",
        onConfirm: () => toggleGlobalBot('off')
      });
    } else {
      toggleGlobalBot('on');
    }
  });
}

async function toggleGlobalBot(status){
  if(_botToggling) return;
  _botToggling = true;

  const btn = $("toggleGlobalBotBtn");
  if(btn){
    btn.disabled     = true;
    btn.style.opacity = '0.6';
    btn.textContent  = "جارٍ...";
  }

  try{
    const body = await apiFetch("/toggle_global_bot.php", {
      method: "POST",
      body: JSON.stringify({ status })
    });

    if(!body.ok && body.status !== 'success') throw new Error(body.message || 'صار خطأ بالسيرفر');

    showToast(
      status === 'on' ? 'تم تشغيل البوت ✅' : 'تم إيقاف البوت ⛔',
      status === 'on' ? 'ok' : 'warn'
    );

  }catch(err){
    showToast(err.message || 'تعذر تحديث حالة البوت', 'error');

  }finally{
    _botToggling = false;
    // ✅ نعيد تحميل الحالة من السيرفر — والـ guard _botLoading يمنع التكرار
    await loadBotStatus();
  }
}
