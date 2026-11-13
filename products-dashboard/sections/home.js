"use strict";
/* ============================================================
   قسم: الرئيسية (نظرة عامة + روابط سريعة)
   ============================================================ */

VIEW_MODULES.home = {
  title: "الرئيسية",
  crumb: "نظرة عامة",
  render(container){
    const cards = [
      { id:"products",         icon:"pkg",        title:"المنتجات",         desc:"عرض المخزون والبحث بالاسم أو الكود" },
      { id:"conversations",    icon:"chat",       title:"المحادثات",        desc:"رد مباشر، أسئلة بانتظارك، وتحكم بالبوت" },
      { id:"knowledge",        icon:"brain",      title:"قاعدة المعرفة",    desc:"الأسئلة والأجوبة الجاهزة للبوت" },
      { id:"employees",        icon:"users",      title:"الموظفين",         desc:"إدارة حسابات الموظفين" },
      { id:"orders",           icon:"reportIcon", title:"الطلبات",          desc:"كل طلبات الصيانة وتغيير حالتها" },
      { id:"report",           icon:"reportIcon", title:"التقرير الشهري",   desc:"طلبات الصيانة والشكاوى والأعطال الشائعة" },
      { id:"dropoff",          icon:"reportIcon", title:"زباين تسرّبوا",    desc:"المحادثات اللي ما اكتملت أو تُركت" },
      { id:"instructions",     icon:"edit",       title:"تعليمات",          desc:"تعديل تعليمات البوت وأسلوب الرد" },
      { id:"botControl",       icon:"power",      title:"التحكم بالبوت",    desc:"تشغيل أو إيقاف البوت كلياً" },
      { id:"rag",              icon:"brain",      title:"دماغ البوت",       desc:"إدارة قاعدة المعرفة الذكية وتحسين الإجابات" },
      { id:"unresolvedImages", icon:"camera",     title:"صور غير معرّفة",   desc:"تدريب البوت على صور القطع الجديدة" },
      { id:"imageCategories",  icon:"camera",     title:"صور واردة أخرى",   desc:"صور وردت من الزباين غير مصنفة" },
    ];

    container.innerHTML = `
      <p style="color:var(--ink-dim);font-size:0.9rem;margin:6px 0 4px;">أهلاً ${esc(state.user?.name || "")} 👋 — اختر قسم للبدء</p>
      <div class="home-grid">
        ${cards.map(c => `
          <div class="home-card" data-go="${c.id}">
            <div class="home-card-icon">${ICONS[c.icon]}</div>
            <h4>${esc(c.title)}</h4>
            <p>${esc(c.desc)}</p>
          </div>
        `).join("")}
      </div>
    `;
    container.querySelectorAll(".home-card").forEach(card => {
      card.addEventListener("click", () => navigate(card.dataset.go));
    });
  }
};
