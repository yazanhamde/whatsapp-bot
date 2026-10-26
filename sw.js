// Service worker for مشغل جنين للصيانة وبيع قطع الغيار — لوحة التحكم الشاملة
// Caches only the static app shell (HTML/JS/CSS/icons). All API data (conversations,
// employees, reports, etc.) is always fetched live — never cached — so the
// owner always sees current data.

const CACHE_NAME = "jenin-dashboard-shell-v6";
const SHELL_FILES = [
  "./index.html",
  "./app.js",
  "./manifest.json",
  "./sections/home.js",
  "./sections/conversations.js",
  "./sections/employees.js",
  "./sections/report.js",
  "./sections/dropoff.js",
  "./sections/instructions.js",
  "./sections/botControl.js",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(SHELL_FILES))
      .catch((err) => console.warn("[sw] precache failed", err))
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  const req = event.request;

  // بس نتدخل بطلبات GET من نفس الأصل (الداشبورد) — أي شي تاني (fonts, POST, cross-origin)
  // منسيبه يمشي طبيعي بدون ما نلمسه، عشان ما نسبب أخطاء غريبة.
  if (req.method !== "GET") return;

  let url;
  try { url = new URL(req.url); } catch (e) { return; }

  if (url.origin !== self.location.origin) return;

  // ما منكاش أي شي تحت /api/ — البيانات لازم تكون حية دايماً.
  if (url.pathname.includes("/api/")) return;

  event.respondWith(
    fetch(req)
      .then((res) => {
        const copy = res.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(req, copy)).catch(() => {});
        return res;
      })
      .catch(async () => {
        const cached = await caches.match(req);
        if (cached) return cached;
        // ما في نسخة كاش ولا نت — نرجع رد بسيط بدل ما نكسر الطلب (يمنع خطأ "Failed to convert value to Response").
        return new Response("تعذر الاتصال بالسيرفر", {
          status: 503,
          statusText: "Service Unavailable",
          headers: { "Content-Type": "text/plain; charset=utf-8" },
        });
      })
  );
});
