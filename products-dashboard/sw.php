<?php
/**
 * sw.php — يحل محل sw.js
 * بيحقن وقت آخر تعديل على ملفات الداشبورد تلقائياً كـ BUILD_TS
 * عشان الكاش ينكسر فوراً بعد أي تعديل بدون ما تلمس أي كود.
 */

// احسب آخر وقت تعديل على أي ملف بالداشبورد
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

// اقرأ محتوى sw.js واستبدل {{BUILD_TS}}
$swContent = file_get_contents($dir . '/sw.js');
$swContent  = str_replace('{{BUILD_TS}}', $buildTs, $swContent);

// ابعته كـ JavaScript بدون كاش
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $swContent;
