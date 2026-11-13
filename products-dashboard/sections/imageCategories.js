"use strict";
/* ============================================================
   قسم: صور الأجهزة، الإعلانات، والتحويلات البنكية
   (الصورة المصنّفة "قطعة غيار" إلها قسمها الخاص أصلاً: sections/unresolvedImages.js)
   ============================================================ */

const imgCatState = { category: "device", page: 1 };

const IMG_CAT_TABS = [
  { id: "device", label: "📷 صور أجهزة" },
  { id: "bank",   label: "💳 تحويلات بنكية" },
  { id: "ads",    label: "📢 إعلانات وأخرى" },
];

VIEW_MODULES.imageCategories = {
  title: "صور واردة أخرى",
  crumb: "صور أجهزة كاملة، إعلانات/سكرين شوتات، ومعلومات مالية حساسة وصلت من الزباين",
  render(container){
    container.innerHTML = `
      <div class="ui-tabs" id="imgCatTabs"></div>
      <div id="imgCatContent"></div>
    `;
    renderImgCatTabs();
    loadImgCat();
  },
  onRefresh(){ loadImgCat(); }
};

function renderImgCatTabs(){
  const wrap = $("imgCatTabs");
  wrap.innerHTML = IMG_CAT_TABS.map(t => `
    <button class="ui-tab ${imgCatState.category === t.id ? 'active' : ''}" data-cat="${t.id}">${t.label}</button>
  `).join("");
  wrap.querySelectorAll("[data-cat]").forEach(btn => {
    btn.addEventListener("click", () => {
      imgCatState.category = btn.dataset.cat;
      imgCatState.page = 1;
      renderImgCatTabs();
      loadImgCat();
    });
  });
}

async function loadImgCat(){
  const content = $("imgCatContent");
  renderSkeletonRows(content, 6);
  try{
    const params = new URLSearchParams({ status: "all", category: imgCatState.category, page: imgCatState.page, per_page: 12 });
    const res = await apiFetch(`/unresolved_images_api.php?${params}`);
    if(!res.ok) throw new Error(res.message || "خطأ في الاستجابة");
    const { items, total, pages } = res.data;

    if(!items || items.length === 0){
      content.innerHTML = `<div class="center-state" style="padding:30px 16px;">${ICONS.search}<h3 style="font-size:0.9rem;">ما في صور بهاي الفئة</h3></div>`;
      return;
    }

    content.innerHTML = `
      <div style="font-size:0.78rem;color:var(--ink-dim);margin-bottom:10px;">الإجمالي: ${fmtInt(total)}</div>
      <div class="ui-grid">
        ${items.map(it => renderImgCatCard(it)).join("")}
      </div>
      ${pages > 1 ? `
        <div class="ui-pager">
          <button ${imgCatState.page <= 1 ? "disabled" : ""} data-nav="-1">السابق</button>
          <span style="font-size:0.8rem;color:var(--ink-dim);">صفحة ${imgCatState.page} من ${pages}</span>
          <button ${imgCatState.page >= pages ? "disabled" : ""} data-nav="1">التالي</button>
        </div>` : ""}
    `;

    content.querySelectorAll("[data-nav]").forEach(btn => {
      btn.addEventListener("click", () => {
        imgCatState.page += parseInt(btn.dataset.nav, 10);
        loadImgCat();
      });
    });
  }catch(err){
    renderCenterState(content, { icon:"alert", title:"صار خطأ", text: err.message || "تعذر تحميل الصور" });
  }
}

function renderImgCatCard(it){
  const img = it.image_url
    ? `<img class="ui-card-img" src="${esc(it.image_url)}" alt="">`
    : `<div class="ui-card-img-ph">🖼️</div>`;
  const waLink = it.customer_phone ? `https://wa.me/${it.customer_phone.replace(/[^0-9]/g,'')}` : null;

  return `
    <div class="ui-card">
      ${img}
      <div class="ui-card-body">
        <div class="ui-card-phone">📱 ${esc(it.customer_phone || 'غير معروف')}</div>
        <div class="ui-card-date">${new Date(it.created_at).toLocaleString('ar')}</div>
        ${waLink ? `<a href="${waLink}" target="_blank" class="ui-btn-train" style="text-align:center;text-decoration:none;">تواصل واتساب</a>` : ""}
      </div>
    </div>
  `;
}
