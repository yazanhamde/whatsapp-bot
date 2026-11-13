<?php
/**
 * index.php — يحل محل index.html
 * بيحقن BUILD_TS (وقت آخر تعديل على ملفات الداشبورد) في كل روابط الـ JS
 * عشان الكاش ينكسر تلقائياً بعد أي تعديل، بدون ما تلمس أي كود.
 *
 * كيف يشتغل:
 *   - اقرأ index.html
 *   - استبدل {{BUILD_TS}} بوقت آخر تعديل على ملفات الداشبورد
 *   - ابعث الرد مع no-cache على index.php نفسه (عشان هو دايماً Fresh)
 *   - الـ JS files هي اللي بتتكاش بالمتصفح، وبتنكسر بـ ?v=TIMESTAMP
 */

$dir   = __DIR__;
$files = array_merge(
    glob($dir . '/*.js'),
    glob($dir . '/*.html'),
    glob($dir . '/sections/*.js')
);

$latestMtime = 0;
foreach ($files as $file) {
    $mtime = filemtime($file);
    if ($mtime > $latestMtime) {
        $latestMtime = $mtime;
    }
}

// BUILD_TS = وقت آخر تعديل (أو وقت الحالي كـ fallback)
$buildTs = $latestMtime > 0 ? (string)$latestMtime : (string)time();

// اقرأ index.html واستبدل كل {{BUILD_TS}}
$html = file_get_contents($dir . '/index.html');
$html = str_replace('{{BUILD_TS}}', $buildTs, $html);

// ابعث الرد — index.php نفسه بدون كاش، الـ JS files بتتكاش بالمتصفح بـ ?v=
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $html;
