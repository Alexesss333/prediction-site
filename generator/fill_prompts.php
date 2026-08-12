<?php
/* Дозаполняет промты картинок у вопросов, которым их не хватило при импорте
   (например ключ Gemini упёрся в лимит). Запускается из CLI:
       docker exec prediction-site php /app/generator/fill_prompts.php [сколько]
   Идёт по одному, с паузой — чтобы не выбить оставшийся ключ. */

require_once __DIR__ . '/docx_import.php';

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 25;
$store = docx_store_load();

$done = 0; $fail = 0;
foreach ($store as $i => $e) {
    if ($done + $fail >= $limit) break;
    if (!empty($e['image_en'])) continue;              // уже есть

    $labels = array_map(fn($o) => $o['label'], $e['options'] ?? []);
    $art = docx_art_prompts($e['question'], $labels);
    if (!$art) { $fail++; echo "— ", mb_substr($e['question'], 0, 50), "\n"; usleep(1500000); continue; }

    $store[$i]['image_en'] = $art['event'];
    foreach (($e['options'] ?? []) as $k => $o) {
        if (!empty($art['options'][$k])) $store[$i]['options'][$k]['image_en'] = $art['options'][$k];
    }
    docx_store_save($store);                            // пишем сразу: обрыв не потеряет сделанное
    $done++;
    echo "✔ ", mb_substr($e['question'], 0, 50), "\n";
    usleep(1200000);                                    // пауза между запросами
}

$left = 0;
foreach ($store as $e) if (empty($e['image_en'])) $left++;
echo "готово: +$done, не вышло: $fail, осталось без промта: $left\n";
