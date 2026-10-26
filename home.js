"use strict";
/* ============================================================
   قسم: الرئيسية (نظرة عامة + روابط سريعة)
   ============================================================ */

VIEW_MODULES.home = {
  title: "الرئيسية",
  crumb: "نظرة عامة",
  render(container){
    const cards = [
      { id:"conversations", icon:"chat",       title:"المحادثات",         desc:"رد مباشر، أسئلة بانتظارك، وتحكم بالبوت" },
      { id:"employees",     icon:"users",      title:"الموظفين",          desc:"إدارة حسابات الموظفين" },
      { id:"report",        icon:"reportIcon", title:"التقرير الشهري",     desc:"طلبات الصيانة والشكاوى والأعطال الشائعة" },
      { id:"dropoff",       icon:"reportIcon", title:"زباين تسرّبوا",     desc:"عرض الزباين اللي ما أكملوا طلبهم" },
      { id:"instructions",  icon:"edit",       title:"تعليمات",          desc:"تعليمات وتوجيهات البوت" },
      { id:"botControl",       icon:"power",   title:"التحكم بالبوت",    desc:"تشغيل أو إيقاف البوت كلياً" },
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
