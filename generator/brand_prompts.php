<?php
/* Сюжет для варианта-компании: узнаваемый предмет вместо логотипа.

   Логотип — это надпись, а буквы модель рисует нечитаемо. Просишь «Nvidia logo
   on a server rack» — выходит стойка без всякой Nvidia. Зато сам предмет
   (видеокарта с вентиляторами, айфон, красный седан) узнаётся без подписи.

   Запуск: docker exec prediction-site php /app/generator/brand_prompts.php [--go] */

require_once __DIR__ . '/docx_import.php';

/* подпись варианта => что рисовать. Ключ ищется в подписи целым словом. */
const BRAND_ART = [
    'ASML'          => 'huge semiconductor lithography machine in a clean room, engineers in white coveralls beside it',
    'Nvidia'        => 'green and black graphics card with three cooling fans held in gloved hands, close up',
    'TSMC'          => 'shining silicon wafer held with tweezers in a clean room, rows of fab equipment behind',
    'Apple'         => 'silver smartphone and thin laptop on a bright minimal wooden table, close up',
    'Microsoft'     => 'laptop running a desktop operating system on an office desk, keyboard and mouse',
    'SAP'           => 'open plan corporate office with rows of desks and large monitors, people working',
    'Shell'         => 'petrol station forecourt at dusk, yellow and red pumps, car refuelling',
    'Tesla'         => 'sleek red electric sedan plugged into a charging post, modern car park',
    'Novo Nordisk'  => 'insulin injection pen and glass medicine vials on a clinic table, close up',
    'MicroStrategy' => 'gold physical bitcoin coins stacked on a corporate desk beside a laptop',
    'Volkswagen'    => 'compact hatchback car on a factory assembly line, robotic arms above',
    'Stellantis'    => 'row of new small city cars parked in a factory yard',
    'Ford Europe'   => 'blue pickup truck and a van parked outside an assembly plant',
    'Steam'         => 'gaming desktop with RGB lighting and two monitors in a dim room',
    'Twitch'        => 'streamer desk with ring light, microphone and webcam, purple lighting',

    'Газпром'       => 'blue gas pipeline running across a snowy Russian field, compressor station behind',
    'Лукойл'        => 'Russian oil refinery towers at dusk, steam rising, pumpjack in front',
    'Сбербанк'      => 'green ATM machine in a Russian bank branch, customer using it',
    'ВТБ'           => 'modern Russian bank office interior, blue accents, teller desks',
    'Т-Банк'        => 'yellow bank card held in a hand above a smartphone, close up',
    'Яндекс'        => 'yellow taxi car on a Moscow street, delivery robot on the pavement',
    'Роснефть'      => 'Russian oil refinery at night, flare stack burning, pipework lit by floodlights',
    'Новатэк'       => 'liquefied natural gas tanker ship moored at an Arctic terminal, storage spheres behind',
    'ЛДПР'          => 'campaign tent with blue and yellow bunting at a Russian street rally, activists handing out leaflets',

    'BTC'           => 'gold physical bitcoin coin standing on a dark desk, warm rim light, close up',
    'Биткоин'       => 'gold physical bitcoin coin standing on a dark desk, warm rim light, close up',
    'ETH'           => 'silver ethereum token with faceted edges on a dark surface, close up',
    'Эфириум'       => 'silver ethereum token with faceted edges on a dark surface, close up',
    'Solana'        => 'purple and teal metal crypto token on a dark reflective surface, close up',
    'XRP'           => 'dark metal crypto coin on a black surface with blue rim light, close up',
    'BNB'           => 'yellow gold crypto token on a dark surface, close up',
    'TRON'          => 'red and black metal crypto token on a dark surface, close up',
];

/* Индексы — не предмет, а торговая площадка: рисуем биржевой зал. */
const INDEX_ART = [
    'DAX'        => 'trading floor of the Frankfurt stock exchange, brokers at desks, big display walls',
    'FTSE 100'   => 'City of London skyline with glass towers, business people crossing a bridge',
    'Nikkei 225' => 'Tokyo stock exchange trading floor, staff at terminals, Japanese signage',
    'SP 500'     => 'New York Stock Exchange trading floor, traders in blue jackets',
    'CAC 40'     => 'La Defense business district towers in Paris, people walking to work',
    'Nasdaq 100' => 'Nasdaq market site in Times Square New York, glass tower and crowds below',
];

function brand_art_for(string $label): ?string {
    $all = BRAND_ART + INDEX_ART;
    foreach ($all as $needle => $art) {
        if (mb_stripos($label, $needle) !== false) return $art;
    }
    return null;
}

$apply = in_array('--go', $argv, true);
$store = docx_store_load();
$n = 0;

foreach ($store as $i => $e) {
    foreach (($e['options'] ?? []) as $k => $o) {
        if (!empty($o['image_url'])) continue;               // нарисованное не трогаем
        $art = brand_art_for($o['label']);
        if (!$art) continue;

        $full = $art . ', natural daylight, no text, no letters';
        if ($apply) $store[$i]['options'][$k]['image_en'] = $full;
        echo str_pad(mb_substr($o['label'], 0, 20), 22), ' -> ', $art, "\n";
        $n++;
    }
}

if ($apply) { docx_store_save($store); echo "\nзаписано: $n\n"; }
else        { echo "\nнашлось: $n (пробный прогон; повтори с --go)\n"; }
