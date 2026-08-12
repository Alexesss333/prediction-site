<?php
/* Выгрузка готовых пачек — по одной папке на исходный файл из Word.

   Берутся только пачки, где картинка есть у каждого вопроса. Внутри папки
   лежат сами картинки и страница index.html: её можно открыть где угодно,
   без сервера, и переслать целиком.

   Запуск: docker exec prediction-site php /app/generator/export_batches.php */

require_once __DIR__ . '/docx_import.php';

const OUT_DIR = __DIR__ . '/../export';
const ROOT    = __DIR__ . '/..';

/** Имя папки: как назывался присланный файл, без расширения. */
function export_slug(string $name): string {
    $s = preg_replace('~\.docx$~ui', '', $name);
    return trim(preg_replace('~[/\\\\:*?"<>|]+~u', '_', $s));
}

function copy_image(?string $url, string $dir, string $prefix): ?string {
    if (!$url) return null;
    $src = ROOT . '/' . $url;
    if (!is_file($src)) return null;
    $name = $prefix . '.webp';
    copy($src, $dir . '/img/' . $name);
    return 'img/' . $name;
}

$store = docx_store_load();

/* Группируем по пачкам, сохраняя порядок вопросов. */
$batches = [];
foreach ($store as $e) {
    $k = $e['batch_name'] ?? $e['batch'] ?? '—';
    $batches[$k][] = $e;
}

if (!is_dir(OUT_DIR)) @mkdir(OUT_DIR, 0777, true);

$madeAny = false;
foreach ($batches as $name => $rows) {
    // Пачка готова, когда картинка есть у каждого вопроса.
    $ready = true;
    foreach ($rows as $e) if (empty($e['image_url'])) { $ready = false; break; }
    if (!$ready) continue;

    $slug = export_slug($name);
    $dir  = OUT_DIR . '/' . $slug;
    if (!is_dir($dir . '/img')) @mkdir($dir . '/img', 0777, true);

    $html = [];
    $html[] = '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
    $html[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $html[] = '<title>' . htmlspecialchars($name) . '</title><style>';
    $html[] = 'body{margin:0;padding:24px;background:#eef1f5;color:#111;'
            . 'font:15px/1.45 -apple-system,Segoe UI,Roboto,sans-serif}';
    $html[] = 'h1{font-size:20px;margin:0 0 18px}';
    $html[] = '.q{background:#fff;border-radius:12px;padding:16px;margin-bottom:14px;'
            . 'box-shadow:0 1px 3px rgba(0,0,0,.12)}';
    $html[] = '.head{display:flex;gap:14px;align-items:flex-start}';
    $html[] = '.head img{width:120px;height:120px;object-fit:cover;border-radius:10px;flex:0 0 auto}';
    $html[] = '.head b{font-size:16px}';
    $html[] = '.opts{display:flex;flex-wrap:wrap;gap:12px;margin-top:14px}';
    $html[] = '.opt{width:150px;text-align:center}';
    $html[] = '.opt img{width:150px;height:110px;object-fit:cover;border-radius:9px;background:#dde3ea}';
    $html[] = '.opt .noimg{display:flex;align-items:center;justify-content:center;height:110px;'
            . 'border-radius:9px;background:#dde3ea;color:#8a93a0;font-size:12px}';
    $html[] = '.opt span{display:block;font-size:13px;margin-top:6px}';
    $html[] = '</style></head><body>';
    $html[] = '<h1>' . htmlspecialchars($name) . ' — вопросов: ' . count($rows) . '</h1>';

    foreach ($rows as $n => $e) {
        $qimg = copy_image($e['image_url'] ?? null, $dir, sprintf('%02d_вопрос', $n + 1));
        $html[] = '<div class="q"><div class="head">';
        if ($qimg) $html[] = '<img src="' . $qimg . '" alt="">';
        $html[] = '<b>' . htmlspecialchars($e['question']) . '</b></div>';

        $html[] = '<div class="opts">';
        foreach (($e['options'] ?? []) as $i => $o) {
            $oimg = copy_image($o['image_url'] ?? null, $dir, sprintf('%02d_ответ%d', $n + 1, $i + 1));
            $html[] = '<div class="opt">';
            $html[] = $oimg ? '<img src="' . $oimg . '" alt="">'
                            : '<div class="noimg">без картинки</div>';
            $html[] = '<span>' . htmlspecialchars($o['label']) . '</span></div>';
        }
        $html[] = '</div></div>';
    }
    $html[] = '</body></html>';

    file_put_contents($dir . '/index.html', implode("\n", $html));

    $opts = 0; $withImg = 0;
    foreach ($rows as $e) {
        $opts += count($e['options'] ?? []);
        foreach (($e['options'] ?? []) as $o) if (!empty($o['image_url'])) $withImg++;
    }
    echo str_pad($name, 22), ' вопросов: ', str_pad((string)count($rows), 4),
         ' ответов с картинкой: ', $withImg, '/', $opts, "\n";
    $madeAny = true;
}

echo $madeAny ? "\nготово: export/\n" : "\nготовых пачек нет\n";
