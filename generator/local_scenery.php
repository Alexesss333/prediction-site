<?php
/* Приводит военные и городские сюжеты к нашей местности.

   Название города модель не читает — «Kramatorsk» для неё пустой звук, и она
   рисует город вообще, по умолчанию западноевропейский: черепица, кирпич,
   узкие улицы. Узнаётся местность не по имени, а по приметам, которые
   рисовалка умеет: хрущёвки с балконами, шифер, синие деревянные заборы,
   тополя, чернозём, «Лады» у обочины, провода над улицей, серое небо.

   Картинки не трогаются — меняются только сюжеты.

   Запуск: docker exec prediction-site php /app/generator/local_scenery.php [--go] */

require_once __DIR__ . '/docx_import.php';

/* Донбасс и юг Украины: равнина, чернозём, пятиэтажки, терриконы. */
const SCENE_UA = 'run-down post-Soviet provincial town, five-storey khrushchyovka apartment blocks with '
    . 'glazed balconies, slate and rusty metal roofs, blue painted wooden fences, poplar and acacia trees, '
    . 'overhead tram wires, old Lada cars parked on the verge, flat black soil fields and a coal spoil tip '
    . 'on the horizon, muddy roadside, overcast grey sky, damp early spring';

/* Российская глубинка: то же, но с берёзами и без терриконов. */
const SCENE_RU = 'Russian provincial town, five-storey panel apartment blocks with glazed balconies, '
    . 'slate roofs, blue painted wooden fences, birch and poplar trees, old Lada and UAZ vehicles, '
    . 'flat snowy fields beyond the last houses, overcast grey sky';

const RU_TROOPS = 'Russian male soldiers in beige and green digital pixel camouflage with sand coloured '
    . 'chest rigs and helmets with ear flaps, carrying Kalashnikov rifles with wooden handguards';

const UA_TROOPS = 'Ukrainian male soldiers in green and brown multicam camouflage with olive chest rigs '
    . 'and helmets, carrying Kalashnikov rifles';

/* Стела на въезде. Ракурсы чередуются, чтобы города не сливались в одну картинку. */
function stele(string $flag, string $troops, int $v): string {
    $shots = [
        'seen from the roadside, a military truck passing on the wet asphalt',
        'photographed across the road, concrete blocks and a barrier at its base',
        'low angle against the grey sky, sandbags stacked at the foot',
        'seen from a distance down the empty road, puddles reflecting it',
    ];
    return 'Large weathered Soviet-era concrete city entrance stele monument beside the road at the edge of town, '
         . $shots[$v % count($shots)] . ', ' . $flag . ', ' . $troops . ', ' . SCENE_UA . ', no text, no letters';
}

$apply = in_array('--go', $argv, true);
$store = docx_store_load();
$n = 0; $v = 0;

// Только вопросы о контроле над конкретным городом: там варианты — стороны,
// и кадром служит въезд в город. «Займёт второе место на выборах» сюда не идёт.
$TOWN_Q = '~контролировать \p{Lu}|взятие \p{Lu}|город Россия возьмёт~ui';

foreach ($store as $i => $e) {
    $q = $e['question'];
    $isTown = preg_match($TOWN_Q, $q);
    $art = null;

    if ($isTown) {
        $art = stele('a bare flagpole beside it', RU_TROOPS . ' at a checkpoint below', $v);
    } elseif (preg_match('~(перемирие|прекращени[ея] огня|линии фронта)~ui', $q)
              && preg_match('~Росси|Украин|СВО|фронт~ui', $q)) {
        $art = RU_TROOPS . ' and ' . UA_TROOPS . ' standing apart on a rural road with weapons lowered, '
             . SCENE_UA . ', no text, no letters';
    } elseif (preg_match('~мобилизаци|подъёмные контрактник~ui', $q)
              || (preg_match('~призыв~ui', $q) && preg_match('~Росси~ui', $q))) {
        $art = 'young Russian men in civilian clothes queuing outside a military enlistment office, '
             . 'officer with a clipboard at the door, ' . SCENE_RU . ', no text, no letters';
    } elseif (preg_match('~военнопленн~ui', $q)) {
        $art = 'thin men in worn tracksuits walking in single file across a rural road towards a bus, '
             . 'soldiers watching from both sides, ' . SCENE_UA . ', no text, no letters';
    }

    if ($art) {
        if ($apply) $store[$i]['image_en'] = $art;
        echo 'В  ', mb_substr($q, 0, 50), "\n";
        $n++; $v++;
    }

    foreach (($e['options'] ?? []) as $k => $o) {
        $lab = $o['label'];
        $oart = null;

        // Варианты-числа («Ноль городов», «Три и более») — не стороны конфликта:
        // им нужен масштаб, а не флаг на стеле.
        $isCount = preg_match('~^(ноль|один|два|три|четыре|\d)~ui', $lab);

        if ($isTown && !$isCount) {
            if (mb_stripos($lab, 'Росс') !== false)
                $oart = stele('a Russian white blue red tricolor flag flying from the pole', RU_TROOPS . ' at a checkpoint below', $v);
            elseif (mb_stripos($lab, 'Украин') !== false)
                $oart = stele('a Ukrainian blue and yellow flag flying from the pole', UA_TROOPS . ' at a checkpoint below', $v);
            elseif (mb_stripos($lab, 'зона') !== false || mb_stripos($lab, 'Серая') !== false)
                $oart = 'Large weathered Soviet-era concrete city entrance stele monument beside an empty road, '
                      . 'bare flagpole with no flag, burnt-out car on the verge, no people, ' . SCENE_UA . ', no text, no letters';
            elseif (mb_stripos($lab, 'Ни один') !== false || mb_stripos($lab, 'не возьм') !== false)
                $oart = 'quiet ordinary street of a post-Soviet provincial town, women with shopping bags, '
                      . 'children by a playground, no soldiers, ' . SCENE_UA . ', no text, no letters';
            else
                $oart = stele('a bare flagpole beside it', RU_TROOPS . ' at a checkpoint below', $v);
        }

        if ($oart) {
            if ($apply) $store[$i]['options'][$k]['image_en'] = $oart;
            echo 'о    ', mb_substr($lab, 0, 34), "\n";
            $n++; $v++;
        }
    }
}

if ($apply) { docx_store_save($store); echo "\nзаписано: $n\n"; }
else        { echo "\nнашлось: $n (пробный прогон; повтори с --go)\n"; }
