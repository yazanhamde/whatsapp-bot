// Service worker for مؤسسة فلسطين — لوحة التحكم الشاملة
// الكاش Shell فقط — كل بيانات الـ API تيجي Live دايماً.
// ⚡ BUILD_TS بيتولد تلقائياً من السيرفر — ما تحتاج تعدّل هاد الملف أبداً.

// ─── BUILD_TS بيتحقن تلقائياً من sw.php ────────────────────
const BUILD_TS = "{{BUILD_TS}}";
const CACHE_NAME = `wa-dashboard-v${BUILD_TS}`;
// ─────────────────────────────────────────────────────────────

const SHELL_FILES = [
  "./app.js",
  "./manifest.json",
  "./sections/home.js",
  "./sections/products.js",
  "./sections/conversations.js",
  "./sections/knowledge.js",
  "./sections/employees.js",
  "./sections/report.js",
  "./sections/botControl.js",
  "./sections/rag.js",
  "./sections/unresolvedImages.js",
  "./sections/imageCategories.js",
  "./sections/dropoff.js",
  "./sections/orders.js",
  "./sections/instructions.js",
];

/* ── Install: cache shell ── */
self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(SHELL_FILES))
      .then(() => self.skipWaiting())
  );
});

/* ── Activate: احذف كل كاشات قديمة ── */
self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((k) => k !== CACHE_NAME)
            .map((k) => {
              console.log(`[SW] حذف كاش قديم: ${k}`);
              return caches.delete(k);
            })
        )
      )
      .then(() => self.clients.claim())
  );
});

/* ── Fetch: Shell → Cache-First | API → Network-Only ── */
self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);

  // ✅ إصلاح: أي طلب لـ goldensketch.com/whatsapp-bot/api لا يُكاش أبداً
  // (الشرط القديم كان بيفوّت بعض الطلبات إذا كان الـ hostname مختلف)
  if (
    url.pathname.includes("/api/") ||
    url.hostname !== self.location.hostname
  ) return;

  // ✅ صفحة الـ HTML (index.php/index.html) دايماً من الشبكة — عشان تجيب أرقام ?v= الجديدة
  if (event.request.mode === "navigate") return;

  // Shell files فقط: Cache-First مع fallback للشبكة
  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) return cached;
      return fetch(event.request).then((res) => {
        const copy = res.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
        return res;
      });
    })
  );
});

/* ── Message: اقبل أمر invalidate من الصفحة ── */
self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
});
