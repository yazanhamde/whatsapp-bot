"use strict";
/* ============================================================
   قسم: تعليمات — هون هو المصدر الفعلي الكامل لشخصية وقواعد بوت
   "محمد" (شخصيته، الـKnowledge Base، وكل القواعد الـ16). البوت
   بيقرا هاد النص حرفياً كل رسالة (شوف MohammadBotTrait::handleMohammad).
   أول ما تفتح الصفحة وما يكون في شي محفوظ بعد، بتشوف القواعد
   الافتراضية الأصلية جاهزة بالمربع — عدّل عليها مباشرة واحفظ.
   ============================================================ */

const instructionsState = { original: "", dirty: false, isDefault: false };

VIEW_MODULES.instructions = {
  title: "تعليمات",
  crumb: "قواعد وشخصية محمد الكاملة — تتحكم فيها من هون بدون ما تلمس الكود",
  render(container){
    container.innerHTML = `<div id="instructionsContent"></div>`;
    loadInstructions();
  },
  onRefresh(){ loadInstructions(); }
};

async function loadInstructions(){
  const content = $("instructionsContent");
  content.innerHTML = `<div class="center-state"><div class="spinner"></div><p>جارٍ التحميل</p></div>`;
  try{
    const body = await apiFetch("/bot_instructions.php");
    const text = (body.data && body.data.instructions) || "";
    instructionsState.original = text;
    instructionsState.dirty = false;
    instructionsState.isDefault = !!(body.data && body.data.is_default);
    renderInstructions(text);
  }catch(err){
    renderCenterState(content, { icon:"alert", title:"صار خطأ", text: err.message || "تعذر تحميل التعليمات" });
  }
}

function renderInstructions(text){
  const content = $("instructionsContent");
  const defaultBadge = instructionsState.isDefault
    ? `<div class="row-card" style="margin-top:10px;border-color:var(--copper-lt);">
         <div class="row-card-body" style="display:flex;gap:10px;align-items:flex-start;">
           <span style="color:var(--copper-lt);flex-shrink:0;margin-top:2px;">${ICONS.alert}</span>
           <span><b>هاي القواعد الافتراضية الأصلية</b> — لسا ما حفظت أي تعديل من الداشبورد، فالبوت شغال بنفس هاد النص بالضبط. عدّل واحفظ لما تحب تغيّر أي قاعدة.</span>
         </div>
       </div>`
    : "";

  content.innerHTML = `
    <div class="row-card">
      <div class="row-card-body" style="display:flex;gap:10px;align-items:flex-start;">
        <span style="color:var(--copper-lt);flex-shrink:0;margin-top:2px;">${ICONS.alert}</span>
        <span>
          هاد النص هو <b>شخصية محمد وكل قواعده بالكامل</b> — أسلوب الرد، معلومات المحل، رسوم الزيارة، قواعد التأكيد، التشخيص المبدئي، التحويل لرمزي، وكل شي تاني. البوت بيقراه حرفياً مع كل رسالة زبون ويلتزم فيه فوراً بدون أي حاجة لتعديل كود.
          <br><br>
          <b>لما تعدّل:</b> غيّر أو أضف أي قاعدة بحرية (نص، أسلوب، عروض مؤقتة، شروط تجارية...). بس خلي بالك: آخر جزء تقني (تعليمات الـ JSON بالأسفل إذا موجودة بالنص) بيعتمد عليه الكود لقراءة رد الموديل — لو ما بدك تلمسه خليه متل ما هو، أو احذفه بالكامل وخليه الكود يضيفه تلقائياً (الكود بيضيف عقد الـJSON التقني دايماً بشكل منفصل بعد قواعدك، مهما كتبت هون).
        </span>
      </div>
    </div>
    ${defaultBadge}

    <div class="row-card" style="margin-top:14px;padding:16px;">
      <textarea id="instructionsTextarea" dir="rtl" placeholder="مثال: لو الزبون سأل عن التوصيل لمنطقة العقبة، قوله التوصيل مش متوفر هلق بس القطع متوفرة..."
        style="width:100%;min-height:420px;background:var(--panel);border:1px solid var(--line-lt);border-radius:10px;
        padding:14px;color:var(--ink);font-family:var(--sans);font-size:0.9rem;line-height:1.7;resize:vertical;"></textarea>
      <div style="margin-top:10px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
          <span id="instructionsCharCount" style="font-size:0.76rem;color:var(--ink-faint);"></span>
          <div style="display:flex;gap:8px;">
            <button class="btn" id="instructionsResetBtn">تراجع</button>
            <button class="btn btn-primary" id="instructionsSaveBtn">${ICONS.check} حفظ القواعد</button>
          </div>
        </div>
        <div style="height:4px;background:rgba(255,255,255,0.08);border-radius:4px;overflow:hidden;">
          <div id="instructionsProgressBar" style="height:100%;border-radius:4px;background:var(--brand);transition:width 0.2s,background 0.2s;width:0%;"></div>
        </div>
      </div>
    </div>
  `;

  const ta = $("instructionsTextarea");
  ta.value = text; // ✅ نضبط النص هون بدل innerHTML عشان نتجنب مشكلة الـ HTML encoding
  const counter = $("instructionsCharCount");
  const saveBtn = $("instructionsSaveBtn");
  const resetBtn = $("instructionsResetBtn");
  const CHAR_LIMIT = 100000;

  const updateCounter = () => {
    const n   = ta.value.length;
    const pct = Math.min((n / CHAR_LIMIT) * 100, 100);
    counter.textContent = `${n.toLocaleString("ar")} / ${CHAR_LIMIT.toLocaleString("ar")} حرف`;
    counter.style.color = n > CHAR_LIMIT ? "var(--danger, #e05a5a)" : "var(--ink-faint)";
    const bar = $("instructionsProgressBar");
    if (bar) {
      bar.style.width = pct + "%";
      bar.style.background = n > CHAR_LIMIT * 0.9 ? (n > CHAR_LIMIT ? "var(--danger)" : "var(--copper)") : "var(--brand)";
    }
  };
  updateCounter();

  ta.addEventListener("input", () => {
    instructionsState.dirty = ta.value !== instructionsState.original;
    updateCounter();
  });

  resetBtn.addEventListener("click", () => {
    ta.value = instructionsState.original;
    instructionsState.dirty = false;
    updateCounter();
  });

  saveBtn.addEventListener("click", async () => {
    if (ta.value.length > CHAR_LIMIT) {
      showToast(`القواعد طويلة كتير (الحد الأقصى ${CHAR_LIMIT.toLocaleString("ar")} حرف)`, "danger");
      return;
    }
    saveBtn.disabled = true;
    const oldLabel = saveBtn.innerHTML;
    saveBtn.innerHTML = "جارٍ الحفظ...";
    try{
      const body = await apiFetch("/bot_instructions.php", {
        method: "POST",
        body: JSON.stringify({ instructions: ta.value }),
      });
      instructionsState.original = (body.data && body.data.instructions) ?? ta.value;
      instructionsState.dirty = false;
      instructionsState.isDefault = false; // بعد أول حفظ ما عاد افتراضي
      showToast(body.message || "تم الحفظ ✅ — محمد رح يستخدم القواعد الجديدة من هلق", "ok");
      renderInstructions(instructionsState.original);
    }catch(err){
      showToast(err.message || "تعذر الحفظ", "danger");
    }finally{
      saveBtn.disabled = false;
      saveBtn.innerHTML = oldLabel;
    }
  });
}
