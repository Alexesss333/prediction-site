<?php
/* Переписывает сюжеты (промты) у ВСЕХ импортированных вопросов по текущим
   правилам из docx_art_prompts. Нужен после правки правил: у уже загруженных
   вопросов промты остаются старыми, кнопка «нарисовать» берёт именно их.

   Запуск:  docker exec prediction-site php /app/generator/repromt_all.php [сколько]

   Идёт по одному с паузой — Gemini-ключ не выдерживает залпом. Пишет после
   каждого вопроса, так что обрыв не теряет сделанное: повторный запуск
   продолжит с того места, где остановились (помечает done_prompt_v2). */

require_once __DIR__ . '/docx_import.php';

const MARK = 'prompt_v2';               // отметка «сюжет уже переписан по новым правилам»

$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 400;
$store = docx_store_load();

$done = 0; $fail = 0; $skip = 0;
foreach ($store as $i => $e) {
    if ($done + $fail >= $limit) break;
    if (!empty($e[MARK])) { $skip++; continue; }

    $labels = array_map(fn($o) => $o['label'], $e['options'] ?? []);
    $art = docx_art_prompts($e['question'], $labels);
    if (!$art) {
        $fail++;
        echo "— ", mb_substr($e['question'], 0, 46), "\n";
        usleep(1500000);
        continue;
    }

    $store[$i]['image_en'] = $art['event'];
    $store[$i][MARK] = true;
    unset($store[$i]['image_url']);                     // старая картинка к новому сюжету не подходит
    foreach (($e['options'] ?? []) as $k => $o) {
        if (!empty($art['options'][$k])) $store[$i]['options'][$k]['image_en'] = $art['options'][$k];
        unset($store[$i]['options'][$k]['image_url']);
    }
    docx_store_save($store);
    $done++;
    echo "✔ ", mb_substr($e['question'], 0, 46), "\n";
    usleep(1200000);
}

$left = 0;
foreach ($store as $e) if (empty($e[MARK])) $left++;
echo "переписано: $done, не вышло: $fail, пропущено (уже новые): $skip, осталось: $left\n";
