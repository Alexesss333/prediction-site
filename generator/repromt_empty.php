<?php
/* Переписывает сюжеты ТОЛЬКО там, где картинка ещё не нарисована.
   Всё, что уже отрисовано (image_url), остаётся нетронутым — и у вопроса,
   и у каждого отдельного ответа.

   Запуск:  docker exec prediction-site php /app/generator/repromt_empty.php [сколько]

   Пишет после каждого вопроса: обрыв не теряет сделанное, повторный запуск
   продолжает с места остановки (отметка prompt_v3). */

require_once __DIR__ . '/docx_import.php';

const MARK = 'prompt_v3';

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 400;
$store = docx_store_load();

$done = 0; $fail = 0; $skip = 0;
foreach ($store as $i => $e) {
    if ($done + $fail >= $limit) break;
    if (!empty($e[MARK])) { $skip++; continue; }

    // Что можно переписать: вопрос — если у него нет картинки;
    // ответ — если картинки нет именно у него.
    $freeEvent = empty($e['image_url']);
    $freeOpts  = [];
    foreach (($e['options'] ?? []) as $k => $o) if (empty($o['image_url'])) $freeOpts[] = $k;

    if (!$freeEvent && !$freeOpts) { $skip++; continue; }   // всё нарисовано — не трогаем

    $labels = array_map(fn($o) => $o['label'], $e['options'] ?? []);
    $art = docx_art_prompts($e['question'], $labels);
    if (!$art) {
        $fail++;
        echo "— ", mb_substr($e['question'], 0, 44), "\n";
        usleep(1500000);
        continue;
    }

    if ($freeEvent && !empty($art['event'])) $store[$i]['image_en'] = $art['event'];
    foreach ($freeOpts as $k) {
        if (!empty($art['options'][$k])) $store[$i]['options'][$k]['image_en'] = $art['options'][$k];
    }
    $store[$i][MARK] = true;
    docx_store_save($store);
    $done++;
    echo "✔ ", mb_substr($e['question'], 0, 44), "\n";
    usleep(1200000);
}

$left = 0;
foreach ($store as $e) if (empty($e[MARK])) $left++;
echo "обновлено: $done, не вышло: $fail, пропущено: $skip, осталось: $left\n";
