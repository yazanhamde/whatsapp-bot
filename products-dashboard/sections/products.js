"use strict";
/* ============================================================
   قسم: المنتجات — قراءة فقط (بحث + صفحات)
   ============================================================ */

const productsState = {
  page: 1,
  pages: 1,
  total: 0,
  search: "",
  condition: "all", // all | new | used
  sort: "name_asc", // name_asc | name_desc | code_asc | code_desc
  newCount: null,
  usedCount: null,
};

const CONDITION_TABS = [
  { key: "all",  label: "الكل" },
  { key: "new",  label: "✨ جديد" },
  { key: "used", label: "♻️ مستعمل" },
];

const SORT_OPTIONS = [
  { key: "name_asc",  label: "الاسم (أ-ي)" },
  { key: "name_desc", label: "الاسم (ي-أ)" },
  { key: "code_asc",  label: "الكود (تصاعدي)" },
  { key: "code_desc", label: "الكود (تنازلي)" },
];

function sortProducts(items, sortKey){
  const sorted = [...items];
  sorted.sort((a, b) => {
    if(sortKey === "name_asc")  return (a.name||"").localeCompare(b.name||"", "ar");
    if(sortKey === "name_desc") return (b.name||"").localeCompare(a.name||"", "ar");
    if(sortKey === "code_asc")  return (a.code||"").localeCompare(b.code||"", undefined, {numeric:true});
    if(sortKey === "code_desc") return (b.code||"").localeCompare(a.code||"", undefined, {numeric:true});
    return 0;
  });
  return sorted;
}

function matchesCondition(p, key){
  if(key === "all") return true;
  const c = (p.condition_status || "").trim();
  if(key === "new")  return c.includes("جديد");
  if(key === "used") return c.includes("مستعمل");
  return true;
}

VIEW_MODULES.products = {
  title: "المنتجات",
  crumb: "عرض المخزون — قراءة فقط",
  render(container){
    container.innerHTML = `
      <div class="search-box">
        ${ICONS.search}
        <input id="prodSearch" type="search" placeholder="دور بالاسم أو الكود..." inputmode="search" value="${esc(productsState.search)}">
      </div>
      <div class="toolbar" id="prodTabs" style="margin:12px 0 0;">
        ${CONDITION_TABS.map(t => `
          <button class="btn ${productsState.condition===t.key?'btn-primary':'btn-ghost'} btn-sm" data-cond="${t.key}">${t.label}</button>
        `).join("")}
        <select id="prodSort" style="background:var(--panel);border:1px solid var(--line-lt);border-radius:10px;padding:7px 10px;color:var(--ink);font-family:var(--sans);font-size:0.78rem;">
          ${SORT_OPTIONS.map(o => `<option value="${o.key}" ${productsState.sort===o.key?'selected':''}>ترتيب: ${o.label}</option>`).join("")}
        </select>
        <button class="btn btn-ghost btn-sm" id="syncProdBtn" style="margin-right:auto;">${ICONS.refresh} مزامنة مع المخزون</button>
        <button class="btn btn-ghost btn-sm" id="diagProdBtn">🩺 تشخيص</button>
      </div>
      <div class="stat-row" id="prodStats"></div>
      <div id="prodContent"></div>
      <div class="pager" id="prodPager" hidden>
        <button id="prodPrev">${ICONS.chevR}</button>
        <span id="prodPageLabel">1 / 1</span>
        <button id="prodNext">${ICONS.chevL}</button>
      </div>
    `;
    $("prodSearch").addEventListener("input", debounce((e) => {
      productsState.search = e.target.value.trim();
      productsState.page = 1;
      loadProducts();
    }, 380));
    $("prodTabs").querySelectorAll("[data-cond]").forEach(btn => {
      btn.addEventListener("click", () => {
        productsState.condition = btn.dataset.cond;
        $("prodTabs").querySelectorAll("[data-cond]").forEach(b => {
          b.className = "btn " + (b.dataset.cond===productsState.condition ? "btn-primary" : "btn-ghost") + " btn-sm";
        });
        renderProductsFromCache();
      });
    });
    $("prodSort").addEventListener("change", (e) => {
      productsState.sort = e.target.value;
      renderProductsFromCache();
    });
    wireDiagnosisButton("diagProdBtn", "/products.php?page=1&per_page=5");
    $("prodPrev").addEventListener("click", () => { if(productsState.page>1){ productsState.page--; loadProducts(); } });
    $("prodNext").addEventListener("click", () => { if(productsState.page<productsState.pages){ productsState.page++; loadProducts(); } });
    $("syncProdBtn").addEventListener("click", () => {
      confirmModal({
        title: "مزامنة المخزون",
        text: "رح تنفتح صفحة مزامنة بتاب جديد، وبتسحب أحدث المنتجات من نظام المخزون (POS). ممكن تاخد شوي وقت حسب عدد المنتجات. بعد ما تخلص، ارجعي هون ودوسي تحديث.",
        confirmLabel: "افتح المزامنة",
        danger: false,
        onConfirm: async () => {
          window.open(CFG.API_BASE + "/sync_products.php?token=" + encodeURIComponent(CFG.SYNC_TOKEN || ""), "_blank");
        }
      });
    });

    loadProducts();
  },
  onRefresh(){ loadProducts(); }
};

let prodCache = []; // آخر دفعة منتجات محمّلة من السيرفر (للفلترة الفورية بدون إعادة تحميل)

async function loadProducts(){
  const content = $("prodContent");
  const pager = $("prodPager");
  if(!content) return;

  let html = '<div class="grid">';
  for(let i=0;i<8;i++) html += `<div class="skel" style="aspect-ratio:1/1.05;border-radius:14px;"></div>`;
  html += "</div>";
  content.innerHTML = html;
  pager.hidden = true;

  try{
    const params = new URLSearchParams({ page: productsState.page, per_page: 24 });
    if(productsState.search) params.set("search", productsState.search);

    const body = await apiFetch("/products.php?" + params.toString());
    const data = body.data || {};
    prodCache = data.items || [];
    productsState.pages = data.pages || 1;
    productsState.total = data.total || 0;
    productsState.newCount = data.new_count ?? null;
    productsState.usedCount = data.used_count ?? null;

    renderProductsFromCache();

    if(productsState.pages > 1){
      pager.hidden = false;
      $("prodPageLabel").textContent = `${fmtInt(productsState.page)} / ${fmtInt(productsState.pages)}`;
      $("prodPrev").disabled = productsState.page <= 1;
      $("prodNext").disabled = productsState.page >= productsState.pages;
    }
  }catch(err){
    renderCenterState(content, { icon:"alert", title:"صار خطأ", text: err.message || "تعذر تحميل المنتجات" });
    showToast(err.message || "تعذر تحميل المنتجات", "error");
  }
}

function renderProductsFromCache(){
  const content = $("prodContent");
  if(!content) return;

  let items = prodCache.filter(p => matchesCondition(p, productsState.condition));
  items = sortProducts(items, productsState.sort);

  if(prodCache.length === 0){
    renderCenterState(content, {
      icon: productsState.search ? "search" : "pkg",
      title: productsState.search ? "ما لقينا شي" : "لا يوجد منتجات بعد",
      text: productsState.search ? `جرب كلمة أو كود ثاني غير «${productsState.search}»` : "المنتجات بتنزل تلقائياً من المزامنة مع نظام الـ POS"
    });
    $("prodStats").innerHTML = `<div class="stat-chip">إجمالي: <b>${fmtInt(productsState.total)}</b></div>`;
    return;
  }

  if(items.length === 0){
    renderCenterState(content, {
      icon: "search",
      title: "ما في شي بهاد التصنيف",
      text: "جربي تبويب تاني أو غيّري الفلتر"
    });
    $("prodStats").innerHTML = `<div class="stat-chip">إجمالي: <b>${fmtInt(productsState.total)}</b></div>`;
    return;
  }

  const outCount = items.filter(p => (parseInt(p.quantity,10)||0) === 0).length;

  // نستخدم العدد الحقيقي لكل المتجر إذا السيرفر يدعمه (بعد التحديث)،
  // وإلا نرجع للعدد القديم (بهاد الصفحة بس) كحل احتياطي.
  const hasStoreWideCounts = productsState.newCount !== null && productsState.usedCount !== null;
  const newLabel  = hasStoreWideCounts ? "✨ جديد بالمتجر" : "✨ جديد بهاد الصفحة";
  const usedLabel = hasStoreWideCounts ? "♻️ مستعمل بالمتجر" : "♻️ مستعمل بهاد الصفحة";
  const newVal  = hasStoreWideCounts ? productsState.newCount  : prodCache.filter(p => matchesCondition(p, "new")).length;
  const usedVal = hasStoreWideCounts ? productsState.usedCount : prodCache.filter(p => matchesCondition(p, "used")).length;

  content.innerHTML = `<div class="grid">${items.map(productCard).join("")}</div>`;
  $("prodStats").innerHTML = `
    <div class="stat-chip">إجمالي بالمتجر: <b>${fmtInt(productsState.total)}</b></div>
    <div class="stat-chip">${newLabel}: <b>${fmtInt(newVal)}</b></div>
    <div class="stat-chip">${usedLabel}: <b>${fmtInt(usedVal)}</b></div>
    <div class="stat-chip ok">متوفر: <b>${fmtInt(items.length-outCount)}</b></div>
    <div class="stat-chip ${outCount>0?'warn':''}">نافذ: <b>${fmtInt(outCount)}</b></div>
  `;
}

function productCard(p){
  const qty = parseInt(p.quantity, 10) || 0;
  const hasImg = p.image_url && !/no_image/i.test(p.image_url);
  const img = hasImg
    ? `<img src="${esc(p.image_url)}" alt="" loading="lazy" onerror="this.parentElement.innerHTML='${ICONS.pkg.replace(/"/g,"&quot;")}'">`
    : ICONS.pkg.replace('<svg ', '<svg class="ph" ');

  return `
    <div class="card">
      <div class="card-img">
        ${img}
        <span class="qty-stamp ${qty===0?'zero':''}">${qty===0 ? 'نفذ' : fmtInt(qty)}</span>
        ${p.condition_status ? `<span style="position:absolute;bottom:7px;right:7px;background:rgba(14,24,18,0.78);color:var(--ink);font-size:0.66rem;padding:3px 7px;border-radius:6px;">${esc(p.condition_status)}</span>` : ''}
      </div>
      <div class="card-body">
        <div class="card-name">${esc(p.name)}</div>
        <div class="card-code">${esc(p.code)}</div>
      </div>
    </div>`;
}
