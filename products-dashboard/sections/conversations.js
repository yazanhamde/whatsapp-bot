"use strict";
/* ============================================================
   قسم: المحادثات — واجهة واتساب حقيقية
   - قائمة الزبائن على اليسار + الرسائل على اليمين (split-pane)
   - polling كل ثانية للرسائل المفتوحة، كل 5 ثواني للقائمة
   ============================================================ */

const convState = {
  list: [],
  filter: "",
  openId: null,
  notifByConv: {},
  _pollTimer: null,
  _listPollTimer: null,
  _lastMsgCount: 0,
  _lastMsgId: null,     // null = لسا ما تحمّلت، 0 = محادثة فاضية
  _threadBusy: false,
  _lastListHash: "",    // ✅ إصلاح: نتتبع تغييرات القائمة بشكل أدق
};

const CONV_MSG_POLL  = 2000;   // تحديث الرسائل كل 3 ثواني (أخف على السيرفر)
const CONV_LIST_POLL = 3000;   // تحديث القائمة كل 5 ثواني

/* ─── Polling ─── */
function startConvPolling() {
  stopConvPolling();
  // poll الرسائل كل ثانية
  convState._pollTimer = setInterval(() => {
    if (convState.openId) silentRefreshThread(convState.openId);
  }, CONV_MSG_POLL);
  // poll القائمة كل 5 ثواني
  convState._listPollTimer = setInterval(() => {
    silentRefreshList();
  }, CONV_LIST_POLL);
}

document.addEventListener("visibilitychange", () => {
  if (!document.hidden && convState._pollTimer) {
    if (convState.openId) silentRefreshThread(convState.openId);
    silentRefreshList();
  }
});

function stopConvPolling() {
  if (convState._pollTimer)     { clearInterval(convState._pollTimer);     convState._pollTimer = null; }
  if (convState._listPollTimer) { clearInterval(convState._listPollTimer); convState._listPollTimer = null; }
}

/* تحديث القائمة بدون spinner */
async function silentRefreshList() {
  try {
    const [convBody, notifBody] = await Promise.all([
      apiFetch("/conversations.php?_=" + Date.now()),
      apiFetch("/notifications.php?_=" + Date.now()).catch(() => ({ items: [] })),
    ]);
    const newList  = (convBody.data && convBody.data.items) || [];
    const newNotif = notifBody.items || [];

    // ✅ إصلاح: نكشف التغيير بـ hash خفيف (last_msg_at + id لأول 20 محادثة + عدد الإشعارات)
    // بدل ما نعيد رسم القائمة كل 5 ثواني حتى لو ما في شي جديد
    const newHash = newList.slice(0, 30).map(c => `${c.id}:${c.last_msg_at}:${c.last_sender}:${(c.last_message||"").length}:${c.bot_paused}:${c.status}`).join("|")
                  + "|notif:" + newNotif.length;
    const changed = newHash !== convState._lastListHash;
    convState._lastListHash = newHash;

    convState.list = newList;
    const map = {};
    newNotif.forEach(n => {
      const cid = n.conversation_id;
      if (!map[cid]) map[cid] = [];
      map[cid].push(n);
    });
    convState.notifByConv = map;
    state.notifCount = notifBody.count || newNotif.length || 0;
    const badge = $("badge-conversations");
    if (badge) {
      if (state.notifCount > 0) { badge.hidden = false; badge.textContent = fmtInt(state.notifCount); }
      else badge.hidden = true;
    }
    // ✅ أعِد الرسم بس لو في تغيير فعلي
    if (changed) renderConvSidebar();
  } catch(e) { console.warn("[poll list]", e); }
}

/* تحديث الرسائل بدون spinner — يجيب الجديدة بس عبر after_id */
async function silentRefreshThread(convId) {
  // ✅ إصلاح: لو المحادثة المفتوحة تغيّرت، ما نحدّث الغلط
  if (convState.openId !== convId) return;
  // ✅ إصلاح: لو _lastMsgId لسا 0 يعني openThread لسا ما خلص تحميل — انتظر الدورة الجاية
  if (convState._lastMsgId === null) return;   // لسا openThread ما خلص
  if (convState._threadBusy) return;            // طلب سابق لسا شغال
  convState._threadBusy = true;
  try {
    // نبعت after_id دايماً — السيرفر يرجع الجديدة بس، وما نضيف نفس الرسائل مرتين
    const url = `/messages.php?conversation_id=${convId}&after_id=${convState._lastMsgId}&_=${Date.now()}`;
    const body = await apiFetch(url);
    const newMessages = body.messages || [];

    // المحادثة تغيّرت أثناء الطلب — تجاهل
    if (convState.openId !== convId) return;
    // ما في جديد — ما نعمل شي
    if (newMessages.length === 0) return;

    // حدّث آخر ID
    convState._lastMsgId    = newMessages[newMessages.length - 1].id || convState._lastMsgId;
    convState._lastMsgCount += newMessages.length;

    const thread = $("waMsgThread");
    if (!thread) return;

    const atBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 80;

    // ✅ أضف الرسائل الجديدة فقط (بدون إعادة رسم كل الـ thread)
    newMessages.forEach(m => {
      // فصل اليوم لو تغيّر
      const dayLabel = fmtDayLabel(m.created_at);
      const lastSep  = thread.querySelector(".chat-day-sep:last-of-type");
      const lastDay  = lastSep ? lastSep.querySelector("span")?.textContent : null;
      if (dayLabel && dayLabel !== lastDay) {
        const sep = document.createElement("div");
        sep.className = "chat-day-sep";
        sep.innerHTML = `<span>${dayLabel}</span>`;
        thread.insertAdjacentElement("beforeend", sep);
      }
      thread.insertAdjacentHTML("beforeend", msgBubble(m));
    });

    if (atBottom) thread.scrollTop = thread.scrollHeight;
  } catch(e) { console.warn("[poll thread]", e); }
  finally { convState._threadBusy = false; }
}

/* ─── Main render ─── */
VIEW_MODULES.conversations = {
  title: "المحادثات",
  crumb: "واتساب — رد مباشر، أسئلة معلّقة، وتحكم بالبوت",
  render(container) {
    convState.openId = null;
    convState._lastMsgCount = 0;
    container.innerHTML = `
      <div id="waShell" class="wa-shell">
        <!-- ══ يسار: قائمة الزبائن ══ -->
        <div class="wa-sidebar" id="waSidebar">
          <div class="wa-sidebar-header">
            <div class="wa-sidebar-title">المحادثات</div>
            <div class="wa-search-wrap">
              <span class="wa-search-icon">${ICONS.search}</span>
              <input id="convSearch" type="search" class="wa-search-input"
                placeholder="دور باسم أو رقم..." inputmode="search">
            </div>
          </div>
          <div id="waConvList" class="wa-conv-list"></div>
        </div>

        <!-- ══ يمين: نافذة المحادثة ══ -->
        <div class="wa-main" id="waMain">
          <div class="wa-empty-state" id="waEmpty">
            <div class="wa-empty-icon">${ICONS.chat}</div>
            <div class="wa-empty-title">اختر محادثة</div>
            <div class="wa-empty-sub">اختر محادثة من القائمة على اليسار لتبدأ</div>
          </div>
        </div>
      </div>
    `;
    $("convSearch").addEventListener("input", debounce(e => {
      convState.filter = e.target.value.trim().toLowerCase();
      renderConvSidebar();
    }, 250));
    loadConversations();
    startConvPolling();
  },
  onRefresh() {
    if (convState.openId) silentRefreshThread(convState.openId);
    silentRefreshList();
  },
  onLeave() {
    stopConvPolling();
  }
};

/* ─── تحميل أولي مع spinner ─── */
async function loadConversations() {
  const list = $("waConvList");
  if (list) renderSkeletonRows(list, 7);
  try {
    const [convBody, notifBody] = await Promise.all([
      apiFetch("/conversations.php"),
      apiFetch("/notifications.php").catch(() => ({ items: [] })),
    ]);
    convState.list = (convBody.data && convBody.data.items) || [];
    const map = {};
    (notifBody.items || []).forEach(n => {
      const cid = n.conversation_id;
      if (!map[cid]) map[cid] = [];
      map[cid].push(n);
    });
    convState.notifByConv = map;
    state.notifCount = notifBody.count || (notifBody.items || []).length || 0;
    const badge = $("badge-conversations");
    if (badge) {
      if (state.notifCount > 0) { badge.hidden = false; badge.textContent = fmtInt(state.notifCount); }
      else badge.hidden = true;
    }
    renderConvSidebar();
  } catch(err) {
    const list = $("waConvList");
    if (list) renderCenterState(list, { icon: "alert", title: "صار خطأ", text: err.message || "تعذر تحميل المحادثات" });
  }
}

/* ─── رندر قائمة اليسار ─── */
function renderConvSidebar() {
  const list = $("waConvList");
  if (!list) return;
  let items = convState.list;
  if (convState.filter) {
    items = items.filter(c =>
      (c.customer_name || "").toLowerCase().includes(convState.filter) ||
      (c.phone || "").toLowerCase().includes(convState.filter)
    );
  }
  items = [...items].sort((a, b) => {
    const aHas = (convState.notifByConv[a.id] || []).length > 0 ? 1 : 0;
    const bHas = (convState.notifByConv[b.id] || []).length > 0 ? 1 : 0;
    if (bHas !== aHas) return bHas - aHas;
    return new Date(b.last_msg_at) - new Date(a.last_msg_at);
  });

  if (items.length === 0) {
    list.innerHTML = `<div style="padding:30px 16px;text-align:center;color:var(--ink-faint);font-size:0.82rem;">${convState.filter ? "ما في نتائج لبحثك" : "ما في محادثات بعد"}</div>`;
    return;
  }

  list.innerHTML = items.map(c => {
    const paused   = !!(parseInt(c.bot_paused, 10));
    const notifs   = convState.notifByConv[c.id] || [];
    const hasNotif = notifs.length > 0;
    const isActive = convState.openId === c.id;
    const lastFrom = c.last_sender === 'bot' ? '🤖 ' : (c.last_sender === 'owner' ? '👤 ' : '');
    const initials = (c.customer_name || c.phone || "?").slice(0, 2);
    return `
      <div class="wa-conv-item ${isActive ? 'wa-conv-item--active' : ''} ${hasNotif ? 'wa-conv-item--notif' : ''}"
           data-conv="${c.id}">
        <div class="wa-avatar">${esc(initials)}</div>
        <div class="wa-conv-info">
          <div class="wa-conv-name">${esc(c.customer_name || c.phone || 'بدون اسم')}</div>
          <div class="wa-conv-preview">
            ${hasNotif
              ? `<span style="color:var(--copper-lt);">🔔 ${esc((notifs[0].question || '').slice(0, 45))}${notifs[0].question?.length > 45 ? '…' : ''}</span>`
              : `${lastFrom}${esc((c.last_message || '').slice(0, 45))}${(c.last_message || '').length > 45 ? '…' : ''}`
            }
          </div>
        </div>
        <div class="wa-conv-meta">
          <span class="wa-conv-time">${timeAgo(c.last_msg_at)}</span>
          ${hasNotif ? `<span class="wa-notif-dot">${notifs.length}</span>` : ''}
          ${paused ? `<span class="wa-paused-dot" title="البوت موقوف">⏸</span>` : ''}
        </div>
      </div>`;
  }).join("");

  list.querySelectorAll("[data-conv]").forEach(el => {
    el.addEventListener("click", () => {
      const id = parseInt(el.dataset.conv, 10);
      // على موبايل: أخفِ السايدبار وأظهر الرسائل
      const shell = $("waShell");
      if (shell) shell.classList.add("wa-thread-open");
      openThread(id);
    });
  });
}

/* ─── فتح المحادثة (الجانب الأيمن) ─── */
async function openThread(convId) {
  // ✅ إصلاح: عيّن openId فوراً قبل أي async عشان الـ polling ما يحدّث محادثة غلط
  convState.openId        = convId;
  convState._lastMsgCount = 0;
  convState._lastMsgId    = null;
  convState._threadBusy   = false;
  // تمييز العنصر النشط في السايدبار
  document.querySelectorAll(".wa-conv-item").forEach(el => {
    el.classList.toggle("wa-conv-item--active", parseInt(el.dataset.conv, 10) === convId);
  });

  const main = $("waMain");
  if (!main) return;
  main.innerHTML = `<div class="wa-thread-loading"><div class="spinner"></div></div>`;

  try {
    const [body, alertsBody] = await Promise.all([
      apiFetch(`/messages.php?conversation_id=${convId}`),
      apiFetch(`/owner_alerts.php?conversation_id=${convId}`).catch(() => ({ items: [] })),
    ]);
    const ownerAlerts = alertsBody.items || [];
    const conv     = body.conversation || {};
    const messages = body.messages || [];
    const paused   = !!(parseInt(conv.bot_paused, 10));
    const pendingNotifs = convState.notifByConv[convId] || [];

    // ✅ احفظ آخر ID من التحميل الأولي — الـ polling بيبدأ من بعده
    convState._lastMsgCount = messages.length;
    convState._lastMsgId    = messages.length > 0 ? (messages[messages.length - 1].id || 0) : 0;
    if (convState.openId !== convId) return;

    main.innerHTML = `
      <!-- رأس المحادثة -->
      <div class="wa-thread-header">
        <button class="wa-back-btn" id="waBackBtn">${ICONS.back}</button>
        <div class="wa-avatar wa-avatar--sm">${(conv.phone || "?").slice(0, 2)}</div>
        <div class="wa-thread-info">
          <div class="wa-thread-name">${esc(conv.customer_name || conv.phone || '')}</div>
          <div class="wa-thread-sub">${esc(conv.phone || '')} · ${fmtInt(messages.length)} رسالة</div>
        </div>
        <button class="btn ${paused ? 'btn-primary' : 'btn-ghost'} btn-sm" id="toggleBotBtn" style="margin-right:auto;">
          ${paused ? ICONS.play : ICONS.pause} ${paused ? 'تشغيل' : 'إيقاف'}
        </button>
      </div>

      <!-- أسئلة معلّقة -->
      ${pendingNotifs.length > 0 ? `
        <div class="wa-pending-notifs" id="pendingNotifsBox">
          ${pendingNotifs.map(n => `
            <div class="wa-pending-item">
              <span class="pill pill-warn" style="font-size:0.72rem;">${ICONS.bell} سؤال معلّق</span>
              <span class="wa-pending-q">${esc(n.question || '')}</span>
              <button class="btn btn-primary btn-sm" data-notif-reply="${n.id}">${ICONS.send} رد</button>
            </div>
          `).join("")}
        </div>
      ` : ''}

      <!-- كل إشعارات رمزي لهالزبون -->
      ${ownerAlerts.length > 0 ? (() => {
        const open = ownerAlerts.filter(a => !a.handled_at);
        const done = ownerAlerts.filter(a =>  a.handled_at);
        const kindLbl = k => ({text:'رسالة',image:'صورة',location:'موقع'})[k] || k;
        return `
        <details class="wa-owner-alerts" ${open.length ? 'open' : ''}>
          <summary>🔔 إشعارات رمزي لهالزبون — ${open.length ? fmtInt(open.length) + ' جديد' : 'ما في جديد'}${done.length ? ' · ' + fmtInt(done.length) + ' تم الرد' : ''}</summary>
          <div class="wa-owner-alerts-list">
            ${open.map(a => `
              <div class="wa-owner-alert">
                <div class="wa-owner-alert-time">${esc(a.created_at || '')} · ${esc(kindLbl(a.kind))}</div>
                <div class="wa-owner-alert-body">${esc(a.message || '')}</div>
                <div style="margin-top:6px;"><button class="btn btn-primary btn-sm" data-alert-reply="${a.id}">${ICONS.send} رد</button></div>
              </div>
            `).join("")}
            ${done.length ? `
              <details class="wa-owner-alerts-done">
                <summary>✅ تم الرد عليها (${fmtInt(done.length)})</summary>
                ${done.map(a => `
                  <div class="wa-owner-alert wa-owner-alert--done">
                    <div class="wa-owner-alert-time">${esc(a.created_at || '')} · ${esc(kindLbl(a.kind))} · ردّ ${esc(a.handled_at || '')}</div>
                    <div class="wa-owner-alert-body">${esc(a.message || '')}</div>
                  </div>
                `).join("")}
              </details>` : ''}
          </div>
        </details>`;
      })() : ''}

      <!-- منطقة الرسائل -->
      <div id="waMsgThread" class="wa-msg-area chat-surface">
        ${renderMessagesWithDaySeparators(messages)}
      </div>

      <!-- صندوق الرد -->
      <div class="wa-reply-box">
        <div class="wa-reply-extra">
          <label class="kb-save-toggle active" id="saveToKbWrap">
            <input type="checkbox" id="saveToKb" checked>
            <span class="kb-box">${ICONS.check}</span>
            <span class="kb-icon">${ICONS.brain}</span>
            <span class="kb-label">احفظ بقاعدة المعرفة</span>
          </label>
        </div>
        <div class="wa-reply-row">
          <textarea id="replyText" class="wa-reply-input" placeholder="اكتب ردك هون..." rows="1"></textarea>
          <button class="wa-send-btn" id="sendReplyBtn">${ICONS.send}</button>
        </div>
      </div>
    `;

    // scroll لآخر رسالة
    const thread = $("waMsgThread");
    if (thread) thread.scrollTop = thread.scrollHeight;

    // auto-resize textarea
    const ta = $("replyText");
    if (ta) {
      ta.addEventListener("input", () => {
        ta.style.height = "auto";
        ta.style.height = Math.min(ta.scrollHeight, 140) + "px";
      });
      ta.addEventListener("keydown", e => {
        if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); $("sendReplyBtn").click(); }
      });
    }

    // زر الرجوع (موبايل)
    $("waBackBtn").addEventListener("click", () => {
      convState.openId = null;
      const shell = $("waShell");
      if (shell) shell.classList.remove("wa-thread-open");
      document.querySelectorAll(".wa-conv-item").forEach(el => el.classList.remove("wa-conv-item--active"));
    });

    // تبديل البوت
    $("toggleBotBtn").addEventListener("click", async () => {
      const btn = $("toggleBotBtn");
      btn.disabled = true;
      try {
        const endpoint = paused ? "/resume.php" : "/pause.php";
        await apiFetch(endpoint, { method: "POST", body: JSON.stringify({ conversation_id: convId }) });
        showToast(paused ? "تم تشغيل البوت" : "تم إيقاف البوت", "ok");
        openThread(convId);
      } catch(err) {
        showToast(err.message || "صار خطأ", "error");
        btn.disabled = false;
      }
    });

    // kb toggle
    $("saveToKbWrap").addEventListener("click", () => {
      setTimeout(() => {
        $("saveToKbWrap").classList.toggle("active", $("saveToKb").checked);
      }, 0);
    });

    // إرسال الرد
    $("sendReplyBtn").addEventListener("click", async () => {
      const text = ($("replyText").value || "").trim();
      if (text.length < 2) { showToast("اكتب رد أطول شوي", "error"); return; }
      const btn = $("sendReplyBtn");
      btn.disabled = true;
      try {
        await apiFetch("/reply.php", {
          method: "POST",
          body: JSON.stringify({
            conversation_id: convId,
            owner_answer: text,
            save_to_kb: $("saveToKb").checked,
          }),
        });
        $("replyText").value = "";
        $("replyText").style.height = "auto";
        showToast("تم إرسال الرد ✅", "ok");
        // حدّث الرسائل فوراً
        await silentRefreshThread(convId);
      } catch(err) {
        showToast(err.message || "تعذر إرسال الرد", "error");
      } finally {
        btn.disabled = false;
      }
    });

    // رد على إشعار من شريط "إشعارات رمزي"
    document.querySelectorAll("[data-alert-reply]").forEach(btn => {
      btn.addEventListener("click", () => {
        const a = ownerAlerts.find(x => String(x.id) === btn.dataset.alertReply);
        if (!a) return;
        // لو الإشعار فيه "رد#N" ولسا السؤال معلّق → رد بنفس الطريقة القديمة (مع تحسين AI)
        const m = (a.message || '').match(/رد#(\d+)/);
        const pendingId = m && pendingNotifs.find(n => String(n.id) === m[1]) ? parseInt(m[1], 10) : null;
        openNotifReplyModal({ id: pendingId, question: a.message || '' }, convId);
      });
    });

    // رد على سؤال معلّق
    document.querySelectorAll("[data-notif-reply]").forEach(btn => {
      btn.addEventListener("click", () => {
        const notif = pendingNotifs.find(n => String(n.id) === btn.dataset.notifReply);
        openNotifReplyModal(notif, convId);
      });
    });

  } catch(err) {
    if (main) main.innerHTML = `<div class="wa-empty-state"><div class="wa-empty-icon">${ICONS.alert}</div><div class="wa-empty-title">صار خطأ</div><div class="wa-empty-sub">${esc(err.message || "تعذر تحميل المحادثة")}</div></div>`;
  }
}

/* ─── modal رد على سؤال معلّق (نفس السابق) ─── */
function openNotifReplyModal(notif, convId) {
  if (!notif) return;
  openModal(`
    <div class="modal-head">
      <h3>الرد على الزبون</h3>
      <button class="modal-close" id="mClose">${ICONS.x}</button>
    </div>
    <div class="modal-body">
      <div class="field">
        <label>الإشعار / السؤال</label>
        <div class="row-card" style="margin:0;background:rgba(0,0,0,0.18);">
          <div class="row-card-body" style="color:var(--ink);white-space:pre-wrap;">${esc(notif.question || '')}</div>
        </div>
      </div>
      <div class="field">
        <label for="ownerAnswer">ردّك</label>
        <textarea id="ownerAnswer" placeholder="اكتب الرد هون..."></textarea>
        <div class="char-count"><span id="ansLen">0</span>/2000</div>
      </div>
      <label class="kb-save-toggle active" id="saveToKbModalWrap">
        <input type="checkbox" id="saveToKbModal" checked>
        <span class="kb-box">${ICONS.check}</span>
        <span class="kb-icon">${ICONS.brain}</span>
        <span class="kb-label">احفظ هاد الرد بقاعدة المعرفة</span>
      </label>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" id="mCancel">إلغاء</button>
      <button class="btn btn-primary" id="mSend">${ICONS.send} إرسال الرد</button>
    </div>
  `);
  $("mClose").onclick = closeModal;
  $("mCancel").onclick = closeModal;
  $("saveToKbModalWrap").addEventListener("click", () => {
    setTimeout(() => { $("saveToKbModalWrap").classList.toggle("active", $("saveToKbModal").checked); }, 0);
  });
  const ta = $("ownerAnswer");
  ta.addEventListener("input", () => { $("ansLen").textContent = ta.value.length; });
  $("mSend").onclick = async () => {
    const text = ta.value.trim();
    if (text.length < 2)    { showToast("اكتب رد أطول شوي", "error"); return; }
    if (text.length > 2000) { showToast("الرد طويل كتير",   "error"); return; }
    const btn = $("mSend");
    btn.disabled = true;
    btn.textContent = "جارٍ الإرسال...";
    try {
      await apiFetch("/reply.php", {
        method: "POST",
        body: JSON.stringify({
          ...(notif.id ? { notification_id: notif.id } : { conversation_id: convId }),
          owner_answer: text,
          save_to_kb: $("saveToKbModal").checked,
        }),
      });
      showToast("تم إرسال الرد ✅", "ok");
      closeModal();
      const list = convState.notifByConv[convId] || [];
      convState.notifByConv[convId] = list.filter(n => n.id !== notif.id);
      openThread(convId);
    } catch(err) {
      showToast(err.message || "تعذر إرسال الرد", "error");
      btn.disabled = false;
      btn.innerHTML = `${ICONS.send} إرسال الرد`;
    }
  };
}

/* ─── helpers (نفس السابق) ─── */
function mediaPathToUrl(mediaPath) {
  if (!mediaPath) return null;
  const marker = "/uploads/";
  const idx = mediaPath.indexOf(marker);
  if (idx === -1) return null;
  const relative = mediaPath.slice(idx + marker.length);
  return CFG.API_BASE.replace(/\/api$/, "") + "/uploads/" + relative;
}

function msgBubble(m) {
  const isCustomer = m.sender === 'customer';
  const isBot      = m.sender === 'bot';
  const isOwner    = m.sender === 'owner';
  const rowClass    = isCustomer ? 'from-customer' : 'from-owner';
  const bubbleClass = isCustomer ? 'bubble-customer' : (isBot ? 'bubble-bot' : 'bubble-owner');
  const tag         = isBot ? '🤖 البوت' : (isOwner ? '👤 رمزي' : '');
  const mediaUrl    = mediaPathToUrl(m.media_path);
  const isImageMsg  = m.msg_type === 'image' && mediaUrl;
  const isAudioMsg  = (m.msg_type === 'audio' || m.msg_type === 'voice') && mediaUrl;
  let mediaHtml = '';
  let bodyText  = esc(m.body || '');
  if (isImageMsg) {
    mediaHtml = `<a href="${esc(mediaUrl)}" target="_blank" rel="noopener" style="display:block;margin-bottom:${m.body?'6px':'0'};"><img src="${esc(mediaUrl)}" alt="صورة" loading="lazy" style="max-width:100%;max-height:260px;border-radius:8px;display:block;object-fit:cover;cursor:zoom-in;"></a>`;
  } else if (isAudioMsg) {
    mediaHtml = `<audio controls preload="none" style="max-width:240px;width:100%;height:36px;margin-bottom:${m.body?'6px':'0'};"><source src="${esc(mediaUrl)}"></audio>`;
    if (m.transcription) bodyText = `<span style="opacity:0.75;font-size:0.78rem;">📝 ${esc(m.transcription)}</span>`;
  } else if (m.msg_type && m.msg_type !== 'text') {
    bodyText = `<span style="opacity:0.7;">[${esc(m.msg_type)}]</span> ` + bodyText;
  }
  const time = fmtTime(m.created_at);
  const checkmarks = !isCustomer ? `<svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 9l3.5 3.5L11 6"/><path d="M6.5 9l3.5 3.5L17 6"/></svg>` : '';
  return `
    <div class="chat-row ${rowClass}">
      <div class="chat-bubble ${bubbleClass}">
        ${tag ? `<span class="bubble-tag">${tag}</span>` : ''}
        ${mediaHtml}
        ${bodyText}
        <div class="bubble-meta"><span>${time}</span>${checkmarks}</div>
      </div>
    </div>`;
}

function fmtTime(s) {
  const d = parseServerDate(s);
  if (!d) return "";
  return new Intl.DateTimeFormat("ar", { hour: "2-digit", minute: "2-digit" }).format(d);
}

function fmtDayLabel(s) {
  const d = parseServerDate(s);
  if (!d) return "";
  const today     = new Date();
  const yesterday = new Date(); yesterday.setDate(today.getDate() - 1);
  const sameDay   = (a, b) => a.toDateString() === b.toDateString();
  if (sameDay(d, today))     return "اليوم";
  if (sameDay(d, yesterday)) return "أمس";
  return new Intl.DateTimeFormat("ar", { day: "numeric", month: "long", year: "numeric" }).format(d);
}

function renderMessagesWithDaySeparators(messages) {
  if (!messages || messages.length === 0)
    return '<p style="color:var(--ink-faint);font-size:0.85rem;text-align:center;padding:20px 0;">ما في رسائل بعد</p>';
  let html = '';
  let lastDay = null;
  messages.forEach(m => {
    const dayLabel = fmtDayLabel(m.created_at);
    if (dayLabel && dayLabel !== lastDay) {
      html += `<div class="chat-day-sep"><span>${dayLabel}</span></div>`;
      lastDay = dayLabel;
    }
    html += msgBubble(m);
  });
  return html;
}
