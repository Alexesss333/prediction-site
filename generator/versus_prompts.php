<?php
/* Сюжет вопроса «что вырастет больше: A или B».

   Прежние сюжеты просили сравнение и графики («stock market performance
   comparison»), а такое рисуется никак: выходит абстрактный экран. Вопрос
   читается, когда предметы лежат рядом в одном кадре — видеокарта и монета
   на столе. Предметы берём те же, что и у вариантов ответа.

   Запуск: docker exec prediction-site php /app/generator/versus_prompts.php [--go] */

require_once __DIR__ . '/docx_import.php';

/* что упомянуто в вопросе => как это выглядит в общем кадре */
const THING_ART = [
    'биткоин'   => 'a gold bitcoin coin',
    'Биткоин'   => 'a gold bitcoin coin',
    'Nvidia'    => 'a green graphics card with cooling fans',
    'ASML'      => 'a silicon wafer',
    'Apple'     => 'a silver smartphone',
    'Tesla'     => 'a red toy-sized electric car model',
    'Газпром'   => 'a blue gas pipeline valve',
    'Сбербанк'  => 'a green bank card',
    'Яндекс'    => 'a yellow taxi car model',
    'Норникель' => 'a raw nickel ore chunk',
    'Лукойл'    => 'a red oil pump nozzle',
    'золото'    => 'a gold bar',
    'нефть'     => 'a small black oil barrel',
    'Brent'     => 'a small black oil barrel',
    'доллар'    => 'a folded US dollar banknote',
    'рубль'     => 'a folded Russian ruble banknote',
    'евро'      => 'a folded euro banknote',
    'серебро'   => 'a silver ingot',
    'платина'   => 'a platinum ingot',
    'палладий'  => 'a palladium ingot',
    'газ'       => 'a blue gas burner flame',
    'Urals'     => 'a small black oil barrel',
    'Shell'     => 'a red and yellow fuel pump nozzle',
    'Роснефть'  => 'a black oil pump nozzle',
    'Новатэк'   => 'a small liquefied gas tank',
    'Татнефть'  => 'a green oil pump nozzle',
    'фунт'      => 'a folded British pound banknote',
    'иена'      => 'a folded Japanese yen banknote',
    'франк'     => 'a folded Swiss franc banknote',
    'юань'      => 'a folded Chinese yuan banknote',
    'Microsoft' => 'a laptop computer',
    'Coinbase'  => 'a smartphone showing a crypto wallet app',
    'Robinhood' => 'a smartphone in a green case',
    'MicroStrategy' => 'a stack of gold bitcoin coins',
];

/* Регион или индекс — не предмет на столе, для них свой кадр. */
const PLACE_ART = [
    'Европа'    => 'the Frankfurt stock exchange trading floor',
    'США'       => 'the New York Stock Exchange trading floor',
    'Азия'      => 'the Tokyo stock exchange trading floor',
    'Россия'    => 'the Moscow Exchange trading floor',
    'DAX'       => 'the Frankfurt stock exchange trading floor',
    'S&P 500'   => 'the New York Stock Exchange trading floor',
    'Nikkei'    => 'the Tokyo stock exchange trading floor',
    'Америка'   => 'the New York Stock Exchange trading floor',
    'РТС'       => 'the Moscow Exchange trading floor',
    'CAC'       => 'the Paris stock exchange trading floor',
    'FTSE'      => 'the London Stock Exchange trading floor',
];

/* Пары, где обе стороны — одно и то же вещество: два сорта нефти, две монеты.
   Общий словарь дал бы им один предмет и картинку из одного объекта. */
const PAIR_ART = [
    'Brent|WTI'      => 'two black oil barrels side by side on a dock, one marked with a blue band and one with a red band',
    'Brent|Urals'    => 'two black oil barrels side by side, one at a European port and one on a snowy Russian dock',
    'Биткоин|эфир'   => 'a gold bitcoin coin and a silver ethereum token side by side on a dark desk, close up',
    'биткоин|эфириум'=> 'a gold bitcoin coin and a silver ethereum token side by side on a dark desk, close up',
];

function versus_art(string $q): ?string {
    foreach (PAIR_ART as $pair => $art) {
        [$a, $b] = explode('|', $pair);
        if (mb_stripos($q, $a) !== false && mb_stripos($q, $b) !== false)
            return $art . ', studio light, no text, no letters';
    }

    $things = [];
    foreach (THING_ART as $needle => $art) {
        if (mb_stripos($q, $needle) !== false && !in_array($art, $things, true)) $things[] = $art;
    }
    if (count($things) >= 2) {
        $list = implode(' and ', array_slice($things, 0, 3));
        return "$list laid out side by side on a dark wooden desk, studio light, close up, no text, no letters";
    }

    $places = [];
    foreach (PLACE_ART as $needle => $art) {
        if (mb_stripos($q, $needle) !== false && !in_array($art, $places, true)) $places[] = $art;
    }
    // Два зала в одном кадре не показать, поэтому берём первый и добавляем
    // город за окном — иначе все сравнения регионов выглядят одинаково.
    if ($places) {
        return $places[0] . ', brokers at their desks, city skyline visible through the windows, busy working day, no text, no letters';
    }
    return null;
}

$apply = in_array('--go', $argv, true);
$store = docx_store_load();
$n = 0; $miss = 0;

foreach ($store as $i => $e) {
    if (!empty($e['image_url'])) continue;                    // нарисованное не трогаем
    // Берём по вопросу, а не по сюжету: «кто вырастет больше», «A или B».
    // Вопрос «когда S&P 500 опустится ниже 6200» — про саму биржу, экран там уместен.
    $q = $e['question'];
    $isVersus = preg_match('~вырастет больше|укрепится|чей индекс|кто вырастет|что вырастет~ui', $q);
    if (!$isVersus) continue;
    if (mb_stripos($q, 'ВВП') !== false) continue;

    $new = versus_art($e['question']);
    if (!$new) { $miss++; echo "?  ", mb_substr($e['question'], 0, 56), "\n"; continue; }

    if ($apply) $store[$i]['image_en'] = $new;
    echo str_pad(mb_substr($e['question'], 0, 46), 48), ' -> ', mb_substr($new, 0, 66), "\n";
    $n++;
}

if ($apply) { docx_store_save($store); echo "\nзаписано: $n, без правила: $miss\n"; }
else        { echo "\nнашлось: $n, без правила: $miss (пробный прогон; повтори с --go)\n"; }
