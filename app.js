"use strict";
/* ============================================================
   مشغل جنين للصيانة وبيع قطع الغيار — لوحة التحكم الشاملة
   app.js — البنية الأساسية: auth / router / api / toast / modal
   ============================================================ */

const CFG = window.APP_CONFIG;

/* ---------- Icons (inline SVG strings, stroke=currentColor) ---------- */
const ICONS = {
  home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 4l9 5.5M5 9.5V19a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9.5"/><path d="M9 20v-6h6v6"/></svg>',
  box: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8l9 5 9-5Z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/></svg>',
  chat: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5c-1.3 0-2.5-.3-3.6-.8L3 21l1.8-5.4A8.5 8.5 0 1 1 21 11.5Z"/></svg>',
  bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9Z"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
  brain: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 2a2.5 2.5 0 0 0-2.5 2.5v.6A3 3 0 0 0 5 8v1a3 3 0 0 0 0 6 3 3 0 0 0 2 2.8v.7A2.5 2.5 0 0 0 9.5 21h0a2.5 2.5 0 0 0 2.5-2.5v-13A2.5 2.5 0 0 0 9.5 2Z"/><path d="M14.5 2a2.5 2.5 0 0 1 2.5 2.5v.6A3 3 0 0 1 19 8v1a3 3 0 0 1 0 6 3 3 0 0 1-2 2.8v.7a2.5 2.5 0 0 1-2.5 2.5h0a2.5 2.5 0 0 1-2.5-2.5v-13A2.5 2.5 0 0 1 14.5 2Z"/></svg>',
  users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  pkg: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 7l2.5-4h13L21 7"/><path d="M9 11a3 3 0 0 0 6 0"/></svg>',
  chart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-4"/></svg>',
  power: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/></svg>',
  search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>',
  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>',
  edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
  trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0-1 14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2L4 6"/></svg>',
  send: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>',
  pause: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>',
  play: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3v18l15-9Z"/></svg>',
  alert: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.3 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.7 3.86a2 2 0 0 0-3.4 0Z"/></svg>',
  x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
  check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
  chevL: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>',
  chevR: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>',
  refresh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6M3 22v-6h6M3.5 9a9 9 0 0 1 15.1-3.7L21 8M20.5 15a9 9 0 0 1-15.1 3.7L3 16"/></svg>',
  back: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>',
  reportIcon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M9 13h6M9 17h6M9 9h1"/></svg>',
  camera: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>',
};

/* ---------- Section registry ---------- */
const SECTIONS = [
  { id: "home",          label: "الرئيسية",          icon: "home" },
  { id: "conversations", label: "المحادثات",         icon: "chat", badgeKey: "notifCount" },
  { id: "employees",     label: "الموظفين",          icon: "users" },
  { id: "report",        label: "التقرير الشهري",     icon: "reportIcon" },
  { id: "dropoff",       label: "زباين تسرّبوا",       icon: "reportIcon" },
  { id: "instructions",  label: "تعليمات",            icon: "edit" },
  { id: "botControl",    label: "التحكم بالبوت",      icon: "power" },
];

/* ---------- Global state ---------- */
const state = {
  token: null,
  user: null,
  view: "home",
  notifCount: 0,
  botActive: null,
  // per-section caches/paging live inside each module
};

/* ---------- DOM refs ---------- */
const $ = (id) => document.getElementById(id);
const loginScreen = $("loginScreen");
const appShell     = $("appShell");
const loginForm    = $("loginForm");
const loginBtn     = $("loginBtn");
const loginError   = $("loginError");
const navList      = $("navList");
const unameEl      = $("uname");
const uroleEl      = $("urole");
const userAvatar   = $("userAvatar");
const viewTitle    = $("viewTitle");
const viewCrumb    = $("viewCrumb");
const viewWrap     = $("viewWrap");
const sidebar      = $("sidebar");
const sidebarOverlay = $("sidebarOverlay");
const menuBtn      = $("menuBtn");
const refreshBtn   = $("refreshBtn");
const toastStack   = $("toastStack");
const modalOverlay = $("modalOverlay");
const modalBox     = $("modalBox");

/* ============================================================
   Utilities
   ============================================================ */
function esc(s){
  const d = document.createElement("div");
  d.textContent = s ?? "";
  return d.innerHTML;
}
function fmtInt(n){ return new Intl.NumberFormat("ar").format(n || 0); }
function fmtMoney(n){ return new Intl.NumberFormat("ar", {maximumFractionDigits:2}).format(n || 0); }
function fmtDate(s){
  if(!s) return "—";
  try{
    const d = new Date(s.replace(" ", "T"));
    if(isNaN(d.getTime())) return s;
    return new Intl.DateTimeFormat("ar", { day:"numeric", month:"short", hour:"2-digit", minute:"2-digit" }).format(d);
  }catch(e){ return s; }
}
function timeAgo(s){
  if(!s) return "—";
  const d = new Date(s.replace(" ", "T"));
  if(isNaN(d.getTime())) return s;
  const diff = (Date.now() - d.getTime())/1000;
  if(diff < 60) return "الآن";
  if(diff < 3600) return `قبل ${Math.floor(diff/60)} د`;
  if(diff < 86400) return `قبل ${Math.floor(diff/3600)} س`;
  return `قبل ${Math.floor(diff/86400)} يوم`;
}
function debounce(fn, ms){
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(()=>fn(...args), ms); };
}

/* ============================================================
   Toast
   ============================================================ */
function showToast(msg, type=""){
  const el = document.createElement("div");
  el.className = "toast" + (type ? " toast-" + type : "");
  el.textContent = msg;
  toastStack.appendChild(el);
  requestAnimationFrame(()=> el.classList.add("show"));
  setTimeout(()=>{
    el.classList.remove("show");
    setTimeout(()=> el.remove(), 300);
  }, 3400);
}

/* ============================================================
   Modal
   ============================================================ */
function openModal(html){
  modalBox.innerHTML = html;
  modalOverlay.classList.add("show");
  document.body.style.overflow = "hidden";
}
function closeModal(){
  modalOverlay.classList.remove("show");
  document.body.style.overflow = "";
  setTimeout(()=>{ modalBox.innerHTML = ""; }, 200);
}
modalOverlay.addEventListener("click", (e) => { if(e.target === modalOverlay) closeModal(); });

function confirmModal({ title, text, confirmLabel="حذف", danger=true, onConfirm }){
  openModal(`
    <div class="modal-head">
      <h3>${esc(title)}</h3>
      <button class="modal-close" id="mClose">${ICONS.x}</button>
    </div>
    <div class="modal-body">
      <div class="confirm-icon">${ICONS.alert}</div>
      <p class="confirm-text">${esc(text)}</p>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" id="mCancel">إلغاء</button>
      <button class="btn ${danger?'btn-danger':'btn-primary'}" id="mConfirm">${esc(confirmLabel)}</button>
    </div>
  `);
  $("mClose").onclick = closeModal;
  $("mCancel").onclick = closeModal;
  $("mConfirm").onclick = async () => {
    $("mConfirm").disabled = true;
    $("mConfirm").textContent = "جارٍ...";
    try{ await onConfirm(); closeModal(); }
    catch(err){ showToast(err.message || "صار خطأ", "error"); $("mConfirm").disabled=false; $("mConfirm").textContent = confirmLabel; }
  };
}

/* ============================================================
   تشخيص عام (مشترك بين كل الأقسام)
   ---------------------------------------------------------------
   بيستدعي أي endpoint مع ?debug=1 (لو الـ endpoint يدعمها بيعرض
   debug_errors بشكل واضح، ولو لأ بيعرض الرد الخام كامل) — مفيد
   لتشخيص أي مشكلة بأي قسم بدون الحاجة لـ cPanel error logs.
   ============================================================ */
async function runDiagnosis(endpointPath, btn){
  const originalLabel = btn ? btn.innerHTML : null;
  if(btn){ btn.disabled = true; btn.innerHTML = "جارٍ الفحص..."; }

  try{
    const sep = endpointPath.includes("?") ? "&" : "?";
    const body = await apiFetch(endpointPath + sep + "debug=1");
    const pretty = JSON.stringify(body, null, 2);
    const hasErrors = body.debug_errors && Object.keys(body.debug_errors).length > 0;
    const hasDebugSupport = body.debug_errors !== undefined;

    openModal(`
      <div class="modal-head">
        <h3>نتيجة التشخيص</h3>
        <button class="modal-close" id="mClose">${ICONS.x}</button>
      </div>
      <div class="modal-body">
        ${hasErrors ? `
          <div class="row-card" style="background:rgba(194,86,74,0.1);border-color:rgba(194,86,74,0.35);margin-bottom:14px;">
            <div class="row-card-body" style="color:var(--danger-lt);">
              <b>في أخطاء فعلية بالسيرفر:</b><br>
              ${Object.entries(body.debug_errors).map(([k,v]) => `<div style="margin-top:6px;"><span class="mono" style="color:var(--copper-lt);">${esc(k)}:</span> ${esc(v)}</div>`).join("")}
            </div>
          </div>
        ` : `
          <div class="row-card" style="background:rgba(95,174,126,0.1);border-color:rgba(95,174,126,0.35);margin-bottom:14px;">
            <div class="row-card-body" style="color:var(--ok);">
              ${hasDebugSupport
                ? 'ما في أخطاء مسجّلة بالسيرفر — البيانات يلي طلعت هي البيانات الفعلية الموجودة بقاعدة البيانات.'
                : 'الطلب رجع رد طبيعي (ok) من السيرفر. هاد القسم ما عندو تفصيل أخطاء متقدّم بعد، بس تقدري تشوفي الرد الكامل تحت.'}
            </div>
          </div>
        `}
        <label style="font-size:0.78rem;color:var(--ink-dim);margin-bottom:6px;display:block;">الرد الكامل من السيرفر (انسخيه وابعتيه لو بدك مساعدة)</label>
        <textarea readonly style="width:100%;min-height:260px;font-family:var(--mono);font-size:0.72rem;direction:ltr;text-align:left;background:rgba(0,0,0,0.3);border:1px solid var(--line-lt);border-radius:10px;padding:11px 13px;color:var(--ink-dim);">${esc(pretty)}</textarea>
      </div>
      <div class="modal-foot">
        <button class="btn btn-ghost" id="mClose2">إغلاق</button>
      </div>
    `);
    $("mClose").onclick = closeModal;
    $("mClose2").onclick = closeModal;

  }catch(err){
    // حتى لو صار خطأ (السيرفر رجّع HTML مش JSON مثلاً)، نوريه كامل بدل ما نخفيه
    openModal(`
      <div class="modal-head">
        <h3>نتيجة التشخيص</h3>
        <button class="modal-close" id="mClose">${ICONS.x}</button>
      </div>
      <div class="modal-body">
        <div class="row-card" style="background:rgba(194,86,74,0.1);border-color:rgba(194,86,74,0.35);margin-bottom:14px;">
          <div class="row-card-body" style="color:var(--danger-lt);"><b>صار خطأ أثناء الفحص:</b><br>${esc(err.message || "خطأ غير معروف")}</div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-ghost" id="mClose2">إغلاق</button>
      </div>
    `);
    $("mClose").onclick = closeModal;
    $("mClose2").onclick = closeModal;
  }finally{
    if(btn){ btn.disabled = false; btn.innerHTML = originalLabel; }
  }
}

// زر تشخيص جاهز — يضاف بسهولة بأي toolbar بأي قسم
function diagnosisButtonHtml(id){
  return `<button class="btn btn-ghost btn-sm" id="${id}" style="margin-right:auto;">🩺 تشخيص</button>`;
}
function wireDiagnosisButton(id, endpointPath){
  const btn = $(id);
  if(btn) btn.addEventListener("click", () => runDiagnosis(endpointPath, btn));
}

/* ============================================================
   Session
   ============================================================ */
function saveSession(){
  try{ sessionStorage.setItem(CFG.STORAGE_KEY, JSON.stringify({ token: state.token, user: state.user })); }
  catch(e){}
}
function loadSession(){
  try{
    const raw = sessionStorage.getItem(CFG.STORAGE_KEY);
    return raw ? JSON.parse(raw) : null;
  }catch(e){ return null; }
}
function clearSession(){
  try{ sessionStorage.removeItem(CFG.STORAGE_KEY); }catch(e){}
}

/* ============================================================
   API helper
   ============================================================ */
async function apiFetch(path, opts={}){
  const headers = {
    "Authorization": "Bearer " + state.token,
    "Content-Type": "application/json",
    ...(opts.headers||{})
  };
  const res = await fetch(CFG.API_BASE + path, { ...opts, headers });
  const raw = await res.text();
  let body;
  try{ body = JSON.parse(raw); }
  catch(e){
    console.error("[apiFetch] non-JSON response from", path, "→", raw);
    const preview = raw.trim().slice(0, 200) || "(رد فاضي من السيرفر)";
    throw new Error(`السيرفر رجّع رد غير صالح (status ${res.status}): ${preview}`);
  }

  if(res.status === 401){
    doLogout(true);
    throw new Error("انتهت الجلسة، سجّل دخول مرة ثانية");
  }
  if(!res.ok || body.ok === false){
    throw new Error(body.message || body.error || "صار خطأ، حاول كمان مرة");
  }
  return body;
}

/* knowledge-base.php accepts either the dashboard key OR the bearer token —
   we send the bearer token like every other endpoint for consistency. */

/* ============================================================
   Auth
   ============================================================ */
loginForm.addEventListener("submit", async (e) => {
  e.preventDefault();
  loginError.style.display = "none";
  loginBtn.disabled = true;
  loginBtn.textContent = "جاري الدخول...";

  const email = $("email").value.trim();
  const password = $("password").value;

  try{
    const res = await fetch(CFG.API_BASE + "/auth/login.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email, password })
    });
    const body = await res.json();
    if(!res.ok || !body.ok){
      throw new Error(body.message || "البريد الإلكتروني أو كلمة المرور غير صحيحة");
    }
    state.token = body.token;
    state.user  = body.user;
    saveSession();
    enterApp();
  }catch(err){
    loginError.textContent = err.message || "تعذر تسجيل الدخول";
    loginError.style.display = "block";
  }finally{
    loginBtn.disabled = false;
    loginBtn.textContent = "دخول";
  }
});

function doLogout(silent){
  state.token = null;
  state.user = null;
  clearSession();
  appShell.hidden = true;
  loginScreen.style.display = "flex";
  loginForm.reset();
  if(!silent) showToast("تم تسجيل الخروج");
}
$("logoutBtn").addEventListener("click", () => doLogout(false));

function enterApp(){
  loginScreen.style.display = "none";
  appShell.hidden = false;

  const name = state.user?.name || "—";
  unameEl.textContent = name;
  uroleEl.textContent = state.user?.role === "admin" ? "صاحب المتجر" : (state.user?.role || "موظف");
  userAvatar.textContent = name.trim().charAt(0) || "؟";

  buildNav();
  navigate("home");
  refreshNotifBadge();
}

/* ============================================================
   Nav / Router
   ============================================================ */
function buildNav(){
  navList.innerHTML = SECTIONS.map(s => `
    <button class="nav-item" data-view="${s.id}">
      ${ICONS[s.icon]}
      <span>${esc(s.label)}</span>
      ${s.badgeKey ? `<span class="nav-badge" id="badge-${s.id}" hidden>0</span>` : ""}
    </button>
  `).join("");
  navList.querySelectorAll(".nav-item").forEach(btn => {
    btn.addEventListener("click", () => {
      navigate(btn.dataset.view);
      closeSidebarMobile();
    });
  });
}

const VIEW_MODULES = {}; // populated by section files: VIEW_MODULES.products = { title, crumb, render, onRefresh }

function navigate(viewId){
  if(!VIEW_MODULES[viewId]) return;
  state.view = viewId;

  navList.querySelectorAll(".nav-item").forEach(btn => {
    btn.classList.toggle("active", btn.dataset.view === viewId);
  });

  const mod = VIEW_MODULES[viewId];
  viewTitle.textContent = mod.title;
  viewCrumb.textContent = mod.crumb || "";
  viewWrap.innerHTML = "";
  mod.render(viewWrap);
}

refreshBtn.addEventListener("click", async () => {
  refreshBtn.classList.add("spin");
  const mod = VIEW_MODULES[state.view];
  try{ if(mod?.onRefresh) await mod.onRefresh(); else mod.render(viewWrap); }
  finally{ setTimeout(()=> refreshBtn.classList.remove("spin"), 400); }
});

/* Mobile sidebar */
menuBtn.addEventListener("click", () => {
  sidebar.classList.add("open");
  sidebarOverlay.classList.add("show");
});
sidebarOverlay.addEventListener("click", closeSidebarMobile);
function closeSidebarMobile(){
  sidebar.classList.remove("open");
  sidebarOverlay.classList.remove("show");
}

/* ============================================================
   Notifications badge (polled from sidebar, lightweight)
   ============================================================ */
async function refreshNotifBadge(){
  try{
    const body = await apiFetch("/notifications.php");
    state.notifCount = body.count || 0;
    const badge = $("badge-conversations");
    if(badge){
      if(state.notifCount > 0){ badge.hidden = false; badge.textContent = fmtInt(state.notifCount); }
      else { badge.hidden = true; }
    }
  }catch(e){ /* silent — badge just won't update */ }
}

/* ============================================================
   Empty / error / skeleton render helpers (shared across sections)
   ============================================================ */
function renderCenterState(container, { icon="alert", title, text }){
  container.innerHTML = `
    <div class="center-state">
      ${ICONS[icon] || ICONS.alert}
      <h3>${esc(title)}</h3>
      <p>${esc(text)}</p>
    </div>`;
}
function renderSkeletonRows(container, n=5){
  let html = '<div class="table-wrap" style="padding:14px;">';
  for(let i=0;i<n;i++){
    html += `<div class="skel" style="height:42px;border-radius:8px;margin-bottom:8px;"></div>`;
  }
  html += "</div>";
  container.innerHTML = html;
}

/* ============================================================
   Boot
   ============================================================ */
function boot(){
  const saved = loadSession();
  if(saved && saved.token){
    state.token = saved.token;
    state.user = saved.user;
    enterApp();
  }
  if("serviceWorker" in navigator){
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("sw.js").catch(()=>{});
    });
  }
}
document.addEventListener("DOMContentLoaded", boot);
