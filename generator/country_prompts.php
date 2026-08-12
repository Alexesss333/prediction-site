<?php
/* Сюжет для варианта-страны: флаг у известного здания.

   Названия стран модель-рисовалка не знает — «Latvia» для неё пустой звук,
   и соседние страны выходят одинаковыми. А вот знаменитые здания она знает,
   поэтому страна задаётся связкой «флаг + постройка», а не словом.

   Запуск: docker exec prediction-site php /app/generator/country_prompts.php [--go] */

require_once __DIR__ . '/docx_import.php';

/* страна => [прилагательное для флага, здание] */
const COUNTRY_ART = [
    'Россия'            => ['Russian tricolor',        "Saint Basil's Cathedral on Red Square in Moscow"],
    'Украина'           => ['Ukrainian blue and yellow', 'Saint Sophia Cathedral with golden domes in Kyiv'],
    'Германия'          => ['German',                  'Brandenburg Gate in Berlin'],
    'Австрия'           => ['Austrian red and white',   'Schonbrunn Palace in Vienna'],
    'Бельгия'           => ['Belgian',                 'Grand Place guild houses in Brussels'],
    'Франция'           => ['French tricolor',         'Eiffel Tower in Paris'],
    'Италия'            => ['Italian',                 'Colosseum in Rome'],
    'Чехия'             => ['Czech',                   'Charles Bridge and Prague Castle'],
    'Венгрия'           => ['Hungarian',               'Hungarian Parliament Building on the Danube in Budapest'],
    'Нидерланды'        => ['Dutch',                   'canal houses and a windmill in Amsterdam'],
    'Литва'             => ['Lithuanian yellow green red', 'Gediminas Tower on its hill in Vilnius'],
    'Польша'            => ['Polish white and red',    'Palace of Culture and Science in Warsaw'],
    'Словакия'          => ['Slovak',                  'Bratislava Castle above the Danube'],
    'Хорватия'          => ['Croatian',                'red tile roofs and city walls of Dubrovnik'],
    'Финляндия'         => ['Finnish blue and white',  'white Helsinki Cathedral with its green dome'],
    'Испания'           => ['Spanish red and yellow',  'Sagrada Familia in Barcelona'],
    'Швеция'            => ['Swedish blue and yellow', 'Gamla Stan old town waterfront in Stockholm'],
    'Корея'             => ['South Korean',            'Gyeongbokgung Palace gate in Seoul'],
    'Иран'              => ['Iranian',                 'blue tiled Shah Mosque dome in Isfahan'],
    'Индия'             => ['Indian',                  'Taj Mahal in Agra'],
    'Турция'            => ['Turkish red',             'Hagia Sophia in Istanbul'],
    'Эстония'           => ['Estonian blue black white', 'medieval towers and red roofs of Tallinn old town'],
    'Бразилия'          => ['Brazilian green and yellow', 'Christ the Redeemer statue above Rio de Janeiro'],
    'Аргентина'         => ['Argentine blue and white', 'Obelisco monument in Buenos Aires'],
    'Ирландия'          => ['Irish green white orange', 'Ha penny Bridge over the Liffey in Dublin'],
    'Мексика'           => ['Mexican',                 'Angel of Independence column in Mexico City'],
    'Япония'            => ['Japanese red and white',  'Senso-ji temple gate in Tokyo'],
    'Саудовская Аравия' => ['Saudi green',             'Kingdom Centre tower in Riyadh'],
    'Китай'             => ['Chinese red',             'Tiananmen Gate in Beijing'],
    'Черногория'        => ['Montenegrin',             'Kotor bay old town below the mountains'],
    'Албания'           => ['Albanian red and black',  'Skanderbeg Square in Tirana'],
    'Молдова'           => ['Moldovan',                'Nativity Cathedral bell tower in Chisinau'],
    'США'               => ['American stars and stripes', 'United States Capitol dome in Washington'],
];

/* Кого называет подпись варианта. Названия склоняются («Германию», «во Франции»),
   поэтому сверяем по основе, отбросив окончание. */
function country_in_label(string $label): ?string {
    $clean = preg_replace('~[^\p{L}\s\-]~u', ' ', $label);
    foreach (COUNTRY_ART as $name => $_) {
        $stem = preg_replace('~[ияа]$~u', '', $name);
        if (preg_match('~\b' . preg_quote($stem, '~') . '~u', $clean)) return $name;
    }
    return null;
}

$apply = in_array('--go', $argv, true);
$store = docx_store_load();
$n = 0;

foreach ($store as $i => $e) {
    foreach (($e['options'] ?? []) as $k => $o) {
        if (!empty($o['image_url'])) continue;              // нарисованное не трогаем
        $c = country_in_label($o['label']);
        if (!$c) continue;

        [$flag, $place] = COUNTRY_ART[$c];
        $art = "$flag flag on a pole in front of the $place, a few people walking by, natural daylight";
        if ($apply) $store[$i]['options'][$k]['image_en'] = $art;
        echo str_pad($o['label'], 26), ' -> ', $art, "\n";
        $n++;
    }
}

if ($apply) { docx_store_save($store); echo "\nзаписано: $n\n"; }
else        { echo "\nнашлось: $n (пробный прогон, запись не делалась; повтори с --go)\n"; }
