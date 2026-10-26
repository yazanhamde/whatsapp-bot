"use strict";
/* ============================================================
   قسم: المحادثات — رد مباشر + إيقاف/تشغيل البوت + الأسئلة المعلّقة
   (الإشعارات مدمجة هون: كل سؤال معلّق بيظهر فوق المحادثة تبعته)
   ============================================================ */

const CONV_CHANNELS = [
  { key: "",           label: "الكل",     emoji: "💬" },
  { key: "whatsapp",   label: "واتساب",   emoji: "🟢" },
  { key: "instagram",  label: "انستغرام", emoji: "📸" },
  { key: "messenger",  label: "فيسبوك",   emoji: "🔵" },
];

const convState = {
  list: [],
  filter: "",
  channel: "",
  openId: null,
  notifByConv: {}, // conversation_id => [notification, ...]
};

VIEW_MODULES.conversations = {
  title: "المحادثات",
  crumb: "رد مباشر، أسئلة معلّقة، وتحكم بالبوت — لكل المنصات",
  render(container){
    convState.openId = null;
    container.innerHTML = `
      <div class="toolbar" id="convChannelTabs" style="gap:8px;flex-wrap:wrap;"></div>
      <div class="search-box" style="margin-top:10px;">
        ${ICONS.search}
        <input id="convSearch" type="search" placeholder="دور باسم الزبون أو رقم الهاتف..." inputmode="search">
      </div>
      <div class="toolbar" style="margin:10px 0 0;">
        ${diagnosisButtonHtml("diagConvBtn")}
      </div>
      <div id="convContent" style="margin-top:2px;"></div>
    `;
    renderConvChannelTabs();
    $("convSearch").addEventListener("input", debounce((e) => {
      convState.filter = e.target.value.trim().toLowerCase();
      renderConvList();
    }, 250));
    wireDiagnosisButton("diagConvBtn", "/conversations.php");
    loadConversations();
  },
  onRefresh(){
    if(convState.openId) openThread(convState.openId);
    else loadConversations();
  }
};

function renderConvChannelTabs(){
  const box = $("convChannelTabs");
  if(!box) return;
  box.innerHTML = CONV_CHANNELS.map(ch => `
    <button data-conv-channel="${ch.key}" class="btn btn-sm ${convState.channel === ch.key ? 'btn-primary' : 'btn-ghost'}">
      ${ch.emoji} ${ch.label}
    </button>
  `).join("");
  box.querySelectorAll("[data-conv-channel]").forEach(btn => {
    btn.addEventListener("click", () => {
      convState.channel = btn.dataset.convChannel;
      renderConvChannelTabs();
      loadConversations();
    });
  });
}

async function loadConversations(){
  const content = $("convContent");
  renderSkeletonRows(content, 6);
  try{
    const channelQS = convState.channel ? `?channel=${convState.channel}` : "";
    const [convBody, notifBody] = await Promise.all([
      apiFetch(`/conversations.php${channelQS}`),
      apiFetch("/notifications.php").catch(() => ({ items: [] })), // ما نوقف الصفحة لو الإشعارات فشلت
    ]);

    convState.list = (convBody.data && convBody.data.items) || [];

    // نبني خريطة: conversation_id → [أسئلة معلّقة]
    const map = {};
    (notifBody.items || []).forEach(n => {
      const cid = n.conversation_id;
      if(!map[cid]) map[cid] = [];
      map[cid].push(n);
    });
    convState.notifByConv = map;
    state.notifCount = notifBody.count || (notifBody.items || []).length || 0;
    const badge = $("badge-conversations");
    if(badge){
      if(state.notifCount > 0){ badge.hidden = false; badge.textContent = fmtInt(state.notifCount); }
      else badge.hidden = true;
    }

    renderConvList();
  }catch(err){
    renderCenterState(content, { icon:"alert", title:"صار خطأ", text: err.message || "تعذر تحميل المحادثات" });
  }
}

function renderConvList(){
  const content = $("convContent");
  if(!content) return;

  let items = convState.list;
  if(convState.filter){
    items = items.filter(c =>
      (c.customer_name||"").toLowerCase().includes(convState.filter) ||
      (c.phone||"").toLowerCase().includes(convState.filter)
    );
  }

  // المحادثات يلي فيها سؤال معلّق تطلع فوق
  items = [...items].sort((a,b) => {
    const aHas = (convState.notifByConv[a.id] || []).length > 0 ? 1 : 0;
    const bHas = (convState.notifByConv[b.id] || []).length > 0 ? 1 : 0;
    return bHas - aHas;
  });

  if(items.length === 0){
    const chLabel = CONV_CHANNELS.find(c => c.key === convState.channel)?.label || "";
    renderCenterState(content, { icon:"chat", title:"لا يوجد محادثات", text: convState.filter ? "ما في نتائج لبحثك" : `ما في محادثات${chLabel ? " على " + chLabel : ""} بعد` });
    return;
  }

  content.innerHTML = items.map(c => {
    const paused = !!(parseInt(c.bot_paused,10));
    const lastFrom = c.last_sender === 'customer' ? '' : (c.last_sender === 'bot' ? '🤖 ' : (c.last_sender === 'owner' ? '👤 ' : ''));
    const notifs = convState.notifByConv[c.id] || [];
    const hasNotif = notifs.length > 0;

    return `
    <div class="row-card" data-conv="${c.id}" style="cursor:pointer;${hasNotif ? 'border-color:var(--copper);' : ''}">
      ${hasNotif ? `
        <div style="display:flex;align-items:center;gap:7px;background:rgba(200,127,74,0.12);border-radius:8px;padding:7px 10px;margin-bottom:2px;">
          <span style="color:var(--copper-lt);flex-shrink:0;">${ICONS.bell}</span>
          <span style="font-size:0.78rem;color:var(--copper-lt);font-weight:700;">
            ${notifs.length > 1 ? `${fmtInt(notifs.length)} أسئلة بانتظار ردّك` : 'سؤال بانتظار ردّك'}
          </span>
        </div>
        <div style="font-size:0.82rem;color:var(--ink);background:rgba(0,0,0,0.15);border-radius:7px;padding:8px 10px;">${esc((notifs[0].question||'').slice(0,100))}${(notifs[0].question||'').length>100?'…':''}</div>
      ` : ''}
      <div class="row-card-top">
        <div>
          <div class="row-card-title">${channelEmoji(c.channel)} ${esc(c.customer_name || c.phone || 'بدون اسم')}</div>
          <div class="row-card-sub mono">${esc(c.phone || '')}</div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
          <span class="pill ${paused?'pill-warn':'pill-ok'}">${paused?'البوت موقوف':'البوت شغّال'}</span>
          <span class="row-card-sub">${timeAgo(c.last_msg_at)}</span>
        </div>
      </div>
      ${!hasNotif ? `<div class="row-card-body">${lastFrom}${esc((c.last_message||'').slice(0,90))}${(c.last_message||'').length>90?'…':''}</div>` : ''}
    </div>`;
  }).join("");

  content.querySelectorAll("[data-conv]").forEach(el => {
    el.addEventListener("click", () => openThread(parseInt(el.dataset.conv,10)));
  });
}

async function openThread(convId){
  convState.openId = convId;
  viewTitle.textContent = "المحادثة";
  viewCrumb.textContent = "جارٍ التحميل...";
  viewWrap.innerHTML = `<div class="center-state"><div class="spinner"></div><p>جارٍ تحميل الرسائل</p></div>`;

  try{
    const body = await apiFetch(`/messages.php?conversation_id=${convId}`);
    const conv = body.conversation || {};
    const messages = body.messages || [];
    const paused = !!(parseInt(conv.bot_paused,10));
    const pendingNotifs = convState.notifByConv[convId] || [];

    viewCrumb.textContent = conv.phone || "";

    viewWrap.innerHTML = `
      <button class="btn btn-ghost btn-sm" id="backToList" style="margin-bottom:12px;">${ICONS.back} رجوع للمحادثات</button>
      <div class="row-card" style="margin-bottom:14px;">
        <div class="row-card-top">
          <div>
            <div class="row-card-title">${channelEmoji(conv.channel)} ${esc(conv.phone || '')}</div>
            <div class="row-card-sub">عدد الرسائل: ${fmtInt(messages.length)}</div>
          </div>
          <button class="btn ${paused?'btn-primary':'btn-ghost'} btn-sm" id="toggleBotBtn">
            ${paused ? ICONS.play : ICONS.pause} ${paused ? 'تشغيل البوت' : 'إيقاف البوت'}
          </button>
        </div>
      </div>

      ${pendingNotifs.length > 0 ? `
        <div id="pendingNotifsBox" style="margin-bottom:14px;display:flex;flex-direction:column;gap:10px;">
          ${pendingNotifs.map(n => `
            <div class="row-card" style="border-color:var(--copper);background:rgba(200,127,74,0.08);">
              <div class="row-card-top">
                <span class="pill pill-warn">${ICONS.bell} سؤال بانتظار الرد</span>
                <span class="row-card-sub">${timeAgo(n.created_at)}</span>
              </div>
              <div class="row-card-body" style="color:var(--ink);">${esc(n.question || '')}</div>
              <div class="row-card-actions">
                <button class="btn btn-primary btn-sm" data-notif-reply="${n.id}">${ICONS.send} رد على هاد السؤال</button>
              </div>
            </div>
          `).join("")}
        </div>
      ` : ''}

      <div id="msgThread" class="chat-surface" style="display:flex;flex-direction:column;max-height:50vh;overflow-y:auto;margin-bottom:14px;">
        ${renderMessagesWithDaySeparators(messages)}
      </div>

      <div class="row-card">
        <label style="font-size:0.78rem;color:var(--ink-dim);margin-bottom:6px;display:block;">رد مباشر للزبون</label>
        <textarea id="replyText" placeholder="اكتب ردك هون..." style="width:100%;background:rgba(0,0,0,0.22);border:1px solid var(--line-lt);border-radius:10px;padding:11px 13px;color:var(--ink);font-family:var(--sans);font-size:0.9rem;outline:none;resize:vertical;min-height:80px;"></textarea>
        <div style="display:flex;justify-content:flex-end;align-items:center;margin-top:12px;gap:10px;flex-wrap:wrap;">
          <button class="btn btn-primary btn-sm" id="sendReplyBtn">${ICONS.send} إرسال الرد</button>
        </div>
      </div>
    `;

    $("backToList").addEventListener("click", () => { convState.openId = null; navigate("conversations"); });

    $("toggleBotBtn").addEventListener("click", async () => {
      const btn = $("toggleBotBtn");
      btn.disabled = true;
      try{
        const endpoint = paused ? "/resume.php" : "/pause.php";
        await apiFetch(endpoint, { method:"POST", body: JSON.stringify({ conversation_id: convId }) });
        showToast(paused ? "تم تشغيل البوت" : "تم إيقاف البوت", "ok");
        openThread(convId);
      }catch(err){
        showToast(err.message || "صار خطأ", "error");
        btn.disabled = false;
      }
    });

    // رد مباشر (مش مرتبط بسؤال معلّق معيّن)
    $("sendReplyBtn").addEventListener("click", async () => {
      const text = $("replyText").value.trim();
      if(text.length < 2){ showToast("اكتب رد أطول شوي", "error"); return; }
      const btn = $("sendReplyBtn");
      btn.disabled = true;
      btn.textContent = "جارٍ الإرسال...";
      try{
        await apiFetch("/reply.php", {
          method:"POST",
          body: JSON.stringify({
            conversation_id: convId,
            owner_answer: text
          })
        });
        showToast("تم إرسال الرد", "ok");
        openThread(convId);
      }catch(err){
        showToast(err.message || "تعذر إرسال الرد", "error");
        btn.disabled = false;
        btn.innerHTML = `${ICONS.send} إرسال الرد`;
      }
    });

    // رد على سؤال معلّق محدد (notification_id) — بيفتح مودال صغير
    document.querySelectorAll("[data-notif-reply]").forEach(btn => {
      btn.addEventListener("click", () => {
        const notif = pendingNotifs.find(n => String(n.id) === btn.dataset.notifReply);
        openNotifReplyModal(notif, convId);
      });
    });

    const thread = $("msgThread");
    if(thread) thread.scrollTop = thread.scrollHeight;

  }catch(err){
    renderCenterState(viewWrap, { icon:"alert", title:"صار خطأ", text: err.message || "تعذر تحميل المحادثة" });
  }
}

function openNotifReplyModal(notif, convId){
  if(!notif) return;
  openModal(`
    <div class="modal-head">
      <h3>الرد على السؤال</h3>
      <button class="modal-close" id="mClose">${ICONS.x}</button>
    </div>
    <div class="modal-body">
      <div class="field">
        <label>السؤال</label>
        <div class="row-card" style="margin:0;background:rgba(0,0,0,0.18);">
          <div class="row-card-body" style="color:var(--ink);">${esc(notif.question || '')}</div>
        </div>
      </div>
      <div class="field">
        <label for="ownerAnswer">ردّك</label>
        <textarea id="ownerAnswer" placeholder="اكتب الرد هون..."></textarea>
        <div class="char-count"><span id="ansLen">0</span>/2000</div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" id="mCancel">إلغاء</button>
      <button class="btn btn-primary" id="mSend">${ICONS.send} إرسال الرد</button>
    </div>
  `);
  $("mClose").onclick = closeModal;
  $("mCancel").onclick = closeModal;
  const ta = $("ownerAnswer");
  ta.addEventListener("input", () => { $("ansLen").textContent = ta.value.length; });

  $("mSend").onclick = async () => {
    const text = ta.value.trim();
    if(text.length < 2){ showToast("اكتب رد أطول شوي", "error"); return; }
    if(text.length > 2000){ showToast("الرد طويل كتير", "error"); return; }
    const btn = $("mSend");
    btn.disabled = true;
    btn.textContent = "جارٍ الإرسال...";
    try{
      await apiFetch("/reply.php", {
        method: "POST",
        body: JSON.stringify({
          notification_id: notif.id,
          owner_answer: text
        })
      });
      showToast("تم إرسال الرد", "ok");
      closeModal();
      // نشيل السؤال من القائمة المحلية فوراً، وبعدين نعيد تحميل الموضوع كامل
      const list = convState.notifByConv[convId] || [];
      convState.notifByConv[convId] = list.filter(n => n.id !== notif.id);
      openThread(convId);
    }catch(err){
      showToast(err.message || "تعذر إرسال الرد", "error");
      btn.disabled = false;
      btn.innerHTML = `${ICONS.send} إرسال الرد`;
    }
  };
}

// مسار الميديا بالقاعدة هو مسار ملف على السيرفر (مثلاً .../whatsapp-bot/uploads/images/x.jpg)
// لازم نحوله لرابط عام نقدر نعرضه بالمتصفح
function mediaPathToUrl(mediaPath){
  if(!mediaPath) return null;
  const marker = "/uploads/";
  const idx = mediaPath.indexOf(marker);
  if(idx === -1) return null;
  const relative = mediaPath.slice(idx + marker.length); // images/xyz.jpg أو audio/xyz.ogg
  return CFG.API_BASE.replace(/\/api$/, "") + "/uploads/" + relative;
}

function msgBubble(m){
  const isCustomer = m.sender === 'customer';
  const isBot = m.sender === 'bot';
  const isOwner = m.sender === 'owner';

  const rowClass = isCustomer ? 'from-customer' : 'from-owner';
  const bubbleClass = isCustomer ? 'bubble-customer' : (isBot ? 'bubble-bot' : 'bubble-owner');
  const tag = isBot ? '🤖 البوت' : (isOwner ? '👤 معاذ' : '');

  const mediaUrl = mediaPathToUrl(m.media_path);
  const isImageMsg = m.msg_type === 'image' && mediaUrl;
  const isAudioMsg = (m.msg_type === 'audio' || m.msg_type === 'voice') && mediaUrl;

  let mediaHtml = '';
  let bodyText = esc(m.body || '');

  if(isImageMsg){
    mediaHtml = `
      <a href="${esc(mediaUrl)}" target="_blank" rel="noopener" style="display:block;margin-bottom:${m.body?'6px':'0'};">
        <img src="${esc(mediaUrl)}" alt="صورة من الزبون" loading="lazy"
             style="max-width:100%;max-height:260px;border-radius:8px;display:block;object-fit:cover;cursor:zoom-in;">
      </a>`;
  } else if(isAudioMsg){
    mediaHtml = `
      <audio controls preload="none" style="max-width:240px;width:100%;height:36px;margin-bottom:${m.body?'6px':'0'};">
        <source src="${esc(mediaUrl)}">
      </audio>`;
    if(m.transcription){
      bodyText = `<span style="opacity:0.75;font-size:0.78rem;">📝 ${esc(m.transcription)}</span>`;
    }
  } else if(m.msg_type && m.msg_type !== 'text'){
    bodyText = `<span style="opacity:0.7;">[${esc(m.msg_type)}]</span> ` + bodyText;
  }

  const time = fmtTime(m.created_at);
  // علامة صح مزدوجة لرسائل معاذ/البوت فقط (زي واتساب — رسالة صادرة منا)
  const checkmarks = !isCustomer ? `
    <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
      <path d="M1 9l3.5 3.5L11 6"/><path d="M6.5 9l3.5 3.5L17 6"/>
    </svg>` : '';

  return `
    <div class="chat-row ${rowClass}">
      <div class="chat-bubble ${bubbleClass}">
        ${tag ? `<span class="bubble-tag">${tag}</span>` : ''}
        ${mediaHtml}
        ${bodyText}
        <div class="bubble-meta">
          <span>${time}</span>
          ${checkmarks}
        </div>
      </div>
    </div>`;
}

function fmtTime(s){
  if(!s) return "";
  const d = new Date(s.replace(" ", "T"));
  if(isNaN(d.getTime())) return "";
  return new Intl.DateTimeFormat("ar", { hour:"2-digit", minute:"2-digit" }).format(d);
}

function fmtDayLabel(s){
  if(!s) return "";
  const d = new Date(s.replace(" ", "T"));
  if(isNaN(d.getTime())) return "";
  const today = new Date();
  const yesterday = new Date(); yesterday.setDate(today.getDate()-1);
  const sameDay = (a,b) => a.toDateString() === b.toDateString();
  if(sameDay(d, today)) return "اليوم";
  if(sameDay(d, yesterday)) return "أمس";
  return new Intl.DateTimeFormat("ar", { day:"numeric", month:"long", year:"numeric" }).format(d);
}

function renderMessagesWithDaySeparators(messages){
  if(!messages || messages.length === 0){
    return '<p style="color:var(--ink-faint);font-size:0.85rem;text-align:center;padding:20px 0;">ما في رسائل بعد</p>';
  }
  let html = '';
  let lastDay = null;
  messages.forEach(m => {
    const dayLabel = fmtDayLabel(m.created_at);
    if(dayLabel && dayLabel !== lastDay){
      html += `<div class="chat-day-sep"><span>${dayLabel}</span></div>`;
      lastDay = dayLabel;
    }
    html += msgBubble(m);
  });
  return html;
}

function channelEmoji(channel){
  const map = { whatsapp: "🟢", instagram: "📸", messenger: "🔵" };
  return map[channel] || "💬";
}
