<?php
/* Сюжет там, где назван населённый пункт: стела на въезде в город.

   Названий городов модель не знает — «checkpoint in Sloviansk» она рисует как
   войну вообще, по умолчанию ближневосточную. Зато бетонную стелу на въезде,
   какая стоит у каждого города, она рисует уверенно, и кадр сразу читается
   как конкретный въезд, а не безымянное поле.

   Буквы на стеле выйдут с ошибками — Flux текст не держит. Это ожидаемо.

   Запуск: docker exec prediction-site php /app/generator/town_prompts.php [--go] */

require_once __DIR__ . '/docx_import.php';

/* Как выглядит местность вокруг: восточноукраинская равнина, а не пустыня. */
const EAST = 'flat black soil farmland with poplar windbreaks, Soviet-era panel blocks and slate roofs behind, overcast grey sky';

/* Российский солдат: без этого модель ставит в кадр ближневосточный камуфляж. */
const RU_TROOPS = 'Russian male soldiers in beige and green digital pixel camouflage with sand coloured chest rigs, carrying Kalashnikov rifles';

/* Города, которые встречаются в вопросах и вариантах. */
const TOWNS = ['Константиновк', 'Славянск', 'Орехов', 'Купянск', 'Краматорск',
               'Николаевк', 'Волчанск', 'Лиман'];

function town_in(string $text): ?string {
    foreach (TOWNS as $t) if (mb_stripos($text, $t) !== false) return $t;
    return null;
}

/* Стела на въезде — общий кадр для любого города. Ракурс задаётся сдвигом,
   чтобы соседние города не выходили одной картинкой. */
function town_art(string $town, int $variant = 0): string {
    $shots = [
        'seen from the roadside with military vehicles passing',
        'photographed from across the road, a column of trucks behind it',
        'with a sandbag position at its base and troops nearby',
    ];
    $shot = $shots[$variant % count($shots)];
    return 'Large Soviet-era concrete city entrance stele monument beside the road at the edge of a provincial town, '
         . $shot . ', ' . RU_TROOPS . ', ' . EAST . ', no text, no letters';
}

/* «Кто будет контролировать X» — вариантами идут стороны, а не города.
   Кадр остаётся тем же въездом в город, меняется только флаг на стеле:
   иначе «Россия» уводит картинку в Москву, к собору Василия Блаженного. */
function control_art(string $side, int $variant = 0): ?string {
    $base = 'Large Soviet-era concrete city entrance stele monument beside the road at the edge of a provincial town, ';
    $tail = ', ' . EAST . ', no text, no letters';

    if (mb_stripos($side, 'Росс') !== false)
        return $base . 'a Russian white blue red tricolor flag flying on it, ' . RU_TROOPS . ' at a checkpoint below' . $tail;
    if (mb_stripos($side, 'Украин') !== false)
        return $base . 'a Ukrainian blue and yellow flag flying on it, Ukrainian male soldiers in green and brown camouflage at a checkpoint below' . $tail;
    if (mb_stripos($side, 'Серая') !== false || mb_stripos($side, 'зона') !== false)
        return $base . 'no flag on the empty pole, deserted road, no people, wrecked car on the verge' . $tail;
    return null;
}

$apply = in_array('--go', $argv, true);
$store = docx_store_load();
$n = 0; $v = 0;

foreach ($store as $i => $e) {
    $qTown = town_in($e['question']);
    if ($qTown) {
        if ($apply) $store[$i]['image_en'] = town_art('', $v);
        echo 'В  ', mb_substr($e['question'], 0, 52), "\n";
        $n++; $v++;
    }
    foreach (($e['options'] ?? []) as $k => $o) {
        if (!empty($o['image_url'])) continue;              // нарисованное не трогаем

        // В вопросе о контроле над городом вариант-сторона рисуется тем же въездом.
        $art = $qTown ? control_art($o['label'], $v) : null;
        if (!$art && town_in($o['label'])) $art = town_art('', $v);
        if (!$art) continue;

        if ($apply) $store[$i]['options'][$k]['image_en'] = $art;
        echo 'о    ', mb_substr($o['label'], 0, 40), "\n";
        $n++; $v++;
    }
}

if ($apply) { docx_store_save($store); echo "\nзаписано: $n\n"; }
else        { echo "\nнашлось: $n (пробный прогон; повтори с --go)\n"; }
