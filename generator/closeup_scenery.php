<?php
/* Крупный план вместо панорамы.

   Картинки показываются квадратом 70×70, и общий план в таком размере
   не читается: стела становится точкой, приметы местности пропадают.
   Кадр держит один предмет во весь кадр, фон размыт, деталей минимум.

   Картинки не трогаются — меняются только сюжеты.

   Запуск: docker exec prediction-site php /app/generator/closeup_scenery.php [--go] */

require_once __DIR__ . '/docx_import.php';

/* Фон одной строкой: узнаётся местность, но в расфокусе и не спорит с предметом. */
const BG_UA = 'blurred background of grey five-storey apartment blocks and bare poplars, overcast day';
const BG_RU = 'blurred background of grey panel blocks and birch trees, overcast day';

const CLOSE = 'close up, subject fills the frame, shallow depth of field, simple composition, no text, no letters';

function stele_close(string $flag, int $v): string {
    $shots = [
        'low angle looking up at a massive weathered concrete city entrance stele',
        'the upper half of a massive weathered concrete city entrance stele',
        'a massive weathered concrete city entrance stele seen up close from the side',
    ];
    return $shots[$v % count($shots)] . ', ' . $flag . ', ' . BG_UA . ', ' . CLOSE;
}

$apply = in_array('--go', $argv, true);
$store = docx_store_load();
$n = 0; $v = 0;

$TOWN_Q = '~контролировать \p{Lu}|взятие \p{Lu}|город Россия возьмёт~ui';

foreach ($store as $i => $e) {
    $q = $e['question'];
    $isTown = preg_match($TOWN_Q, $q);
    $art = null;

    if ($isTown) {
        $art = stele_close('bare flagpole against the sky', $v);
    } elseif (preg_match('~(перемирие|прекращени[ея] огня|линии фронта)~ui', $q)
              && preg_match('~Росси|Украин|СВО|фронт~ui', $q)) {
        $art = 'two soldiers face to face from the chest up, weapons lowered, one in beige pixel camouflage '
             . 'and one in green camouflage, ' . BG_UA . ', ' . CLOSE;
    } elseif (preg_match('~мобилизаци|подъёмные контрактник~ui', $q)
              || (preg_match('~призыв~ui', $q) && preg_match('~Росси~ui', $q))) {
        $art = 'a young man in a jacket holding a military summons paper, waist up portrait, '
             . 'officer blurred behind him, ' . BG_RU . ', ' . CLOSE;
    } elseif (preg_match('~военнопленн~ui', $q)) {
        $art = 'a thin man in a worn tracksuit seen from the chest up, tired face, bus door blurred behind, '
             . BG_UA . ', ' . CLOSE;
    }

    if ($art) {
        if ($apply) $store[$i]['image_en'] = $art;
        echo 'В  ', mb_substr($q, 0, 50), "\n";
        $n++; $v++;
    }

    foreach (($e['options'] ?? []) as $k => $o) {
        $lab = $o['label'];
        $isCount = preg_match('~^(ноль|один|два|три|четыре|\d)~ui', $lab);
        if (!$isTown || $isCount) continue;

        if (mb_stripos($lab, 'Росс') !== false)
            $oart = stele_close('a Russian white blue red tricolor flag filling the upper frame', $v);
        elseif (mb_stripos($lab, 'Украин') !== false)
            $oart = stele_close('a Ukrainian blue and yellow flag filling the upper frame', $v);
        elseif (mb_stripos($lab, 'зона') !== false || mb_stripos($lab, 'Серая') !== false)
            $oart = 'a bare rusty flagpole with no flag against a grey sky, close up, '
                  . 'burnt-out car blurred behind, ' . CLOSE;
        elseif (mb_stripos($lab, 'Ни один') !== false || mb_stripos($lab, 'не возьм') !== false)
            $oart = 'an elderly woman with a shopping bag walking on a pavement, waist up, calm face, '
                  . BG_UA . ', ' . CLOSE;
        else
            $oart = stele_close('bare flagpole against the sky', $v);

        if ($apply) $store[$i]['options'][$k]['image_en'] = $oart;
        echo 'о    ', mb_substr($lab, 0, 34), "\n";
        $n++; $v++;
    }
}

if ($apply) { docx_store_save($store); echo "\nзаписано: $n\n"; }
else        { echo "\nнашлось: $n (пробный прогон; повтори с --go)\n"; }
