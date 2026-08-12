<?php
/**
 * Prediction events — auto-generator (core logic).
 *
 * HTTP API (GET or POST):
 *   ?action=generate&preset=crypto_1h
 *   ?action=generate&category=stocks&type=closed&timeframe=15m&count=5
 *   ?action=list        return current events (JSON)
 *   ?action=clear       wipe all events
 *   ?action=presets     list presets
 *   ?action=meta        categories + mandatory timeframes (for the admin UI)
 *
 * Event shape:
 *   { id, type:"closed"|"open", category, category_label, timeframe, timeframe_label,
 *     question, image,                       // question ALWAYS has an image
 *     options:[ { label, price, image? } ],  // image only for OPEN answers
 *     created_at, resolves_at, source }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// папка данных (можно переопределить через PS_DATA_DIR — используется тестами для изоляции)
if (!defined('PS_DATA')) define('PS_DATA', getenv('PS_DATA_DIR') ?: __DIR__ . '/../data');

$DATA  = PS_DATA . '/events.json';
$SCHED = PS_DATA . '/schedules.json';   // auto-generation schedules
$MAX_EVENTS = 500;

/* ---------- storage ---------- */
function jload($f){ return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function jsave($f, $a){
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0777, true);
    // Списки (events/news/schedules — только числовые ключи) реиндексируем в JSON-массив;
    // ассоциативные массивы (config) сохраняем как объект, не теряя ключей.
    if (is_array($a)){
        $allInt = true;
        foreach (array_keys($a) as $k){ if (!is_int($k)){ $allInt = false; break; } }
        if ($allInt) $a = array_values($a);
    }
    $json = json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    // АТОМАРНАЯ запись: во временный файл, затем rename. Иначе воркер и веб-сервер,
    // читая/записывая один файл одновременно, ловят полу-записанный JSON → jload вернёт []
    // → следующая запись затирает данные (так терялись события в events.json).
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $json) !== false){ @rename($tmp, $f); }
    else { @unlink($tmp); }
}
function load_events($f){ return jload($f); }
function save_events($f, $a){ jsave($f, $a); }

/* ---------- helpers ---------- */
function rid(){ return bin2hex(random_bytes(6)); }
function pick($arr){ return $arr[array_rand($arr)]; }
/* выбор актива ПО КРУГУ (ротация), чтобы не повторялись одни и те же (BTC→ETH→SOL→…) */
function pick_rotating($pool, $cat){
    if (!$pool) return null;
    $pool = array_values($pool);
    if (count($pool) <= 1) return $pool[0];
    $f = PS_DATA . '/asset_rr.json';
    $rr = jload($f); if (!is_array($rr)) $rr = [];
    $i = ((int)($rr[$cat] ?? -1) + 1) % count($pool);
    $rr[$cat] = $i; jsave($f, $rr);
    return $pool[$i];
}
function pct($min,$max){ return random_int($min,$max); }

/** Inline SVG badge — ПОЛНОЕ название, вписанное в квадрат (перенос по словам + авто-размер шрифта). */
function img_badge($label, $bg = '#2b3550', $fg = '#ffffff'){
    $label = trim((string)$label); if ($label === '') $label = '?';
    // перенос по словам: строки максимум ~9 символов, до 3 строк
    $words = preg_split('/\s+/u', $label);
    $lines = []; $cur = '';
    foreach ($words as $w){
        $try = ($cur === '') ? $w : $cur . ' ' . $w;
        if ($cur === '' || mb_strlen($try, 'UTF-8') <= 9) $cur = $try;
        else { $lines[] = $cur; $cur = $w; }
    }
    if ($cur !== '') $lines[] = $cur;
    if (count($lines) > 3) $lines = array_slice($lines, 0, 3);
    $n = count($lines);
    $maxLen = 1; foreach ($lines as $l){ $maxLen = max($maxLen, mb_strlen($l, 'UTF-8')); }
    // размер шрифта: по ширине (~224/длина) и по высоте (120/строк), в пределах 11..34
    $fs = max(11, min(34, min((int)floor(224 / $maxLen), (int)floor(110 / max(1,$n)))));
    $lh = $fs * 1.15;
    $tspans = '';
    foreach ($lines as $i=>$l){
        $y = 70 + ($i - ($n - 1) / 2) * $lh;
        $tspans .= '<tspan x="70" y="' . round($y, 1) . '">' . htmlspecialchars($l, ENT_QUOTES) . '</tspan>';
    }
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="140" height="140" viewBox="0 0 140 140">'
         . '<rect width="140" height="140" rx="22" fill="' . $bg . '"/>'
         . '<text font-family="Arial,Helvetica,sans-serif" font-size="' . $fs . '" font-weight="700" '
         . 'fill="' . $fg . '" text-anchor="middle" dominant-baseline="central">' . $tspans . '</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/* ---------- реальные котировки (крипта: Binance, бесплатно, без ключа) ---------- */
$PRICES = PS_DATA . '/prices.json';
$CRYPTO_BINANCE = ['BTC'=>'BTCUSDT','ETH'=>'ETHUSDT','SOL'=>'SOLUSDT','XRP'=>'XRPUSDT','DOGE'=>'DOGEUSDT',
                   'TON'=>'TONUSDT','BNB'=>'BNBUSDT','ADA'=>'ADAUSDT','TRX'=>'TRXUSDT','AVAX'=>'AVAXUSDT'];
/** Текущие цены крипты (кэш 30с, один запрос на все символы). */
function crypto_prices(){
    global $PRICES, $CRYPTO_BINANCE;
    $cache = jload($PRICES);
    if (is_array($cache) && !empty($cache['ts']) && (time() - $cache['ts'] < 30) && !empty($cache['prices']))
        return $cache['prices'];
    $syms = array_values($CRYPTO_BINANCE);
    $url = 'https://data-api.binance.vision/api/v3/ticker/price?symbols=' . rawurlencode('["' . implode('","', $syms) . '"]');
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $prices = (is_array($cache) && !empty($cache['prices'])) ? $cache['prices'] : [];
    if ($resp && $code === 200){
        $arr = json_decode($resp, true);
        if (is_array($arr)){
            $bySym = [];
            foreach ($arr as $row){ if (isset($row['symbol'],$row['price'])) $bySym[$row['symbol']] = (float)$row['price']; }
            foreach ($CRYPTO_BINANCE as $t=>$s){ if (isset($bySym[$s])) $prices[$t] = $bySym[$s]; }
            jsave($PRICES, ['ts'=>time(), 'prices'=>$prices]);
        }
    }
    return $prices;
}
/** Реальная цена актива или null (сейчас реалтайм бесплатно — только крипта). */
function asset_price($cat, $ticker){
    if ($cat === 'crypto'){ $p = crypto_prices(); return $p[$ticker] ?? null; }
    return null;
}
function fmt_price($p){
    if ($p >= 1000) return '$' . number_format($p, 0, '.', ' ');
    if ($p >= 1)    return '$' . number_format($p, 2, '.', ' ');
    return '$' . rtrim(rtrim(number_format($p, 6, '.', ''), '0'), '.');
}

/* ---------- реальные ЛОГО компаний/активов (из dossier, для карточек рынков) ---------- */
function logo_manifest(){
    static $m = null;
    if ($m === null){ $j = jload(PS_DATA . '/logos/_manifest.json'); $m = is_array($j) ? $j : []; }
    return $m;
}
/* тикер сайта -> имя лого в манифесте */
$TICKER_LOGO = [
    'BTC'=>'BTC','ETH'=>'ETH','SOL'=>'Солана (SOL)','XRP'=>'XRP','DOGE'=>'Dogecoin (DOGE)','TON'=>'Toncoin (TON)',
    'BNB'=>'BNB','ADA'=>'Cardano (ADA)','TRX'=>'Tron (TRX)',
    'AAPL'=>'Apple','TSLA'=>'Tesla','NVDA'=>'Nvidia','MSFT'=>'Microsoft','AMZN'=>'Amazon','GOOGL'=>'Google','META'=>'Meta','NFLX'=>'Netflix',
    'USD'=>'Доллар','RUB'=>'Рубль',
    'Золото'=>'Золото','Серебро'=>'Серебро','Платина'=>'Платина','Палладий'=>'Палладий','Медь'=>'Металл',
    'IMOEX'=>'IMOEX','Нефть'=>'Нефть','Газ'=>'Газ',
];
/** URL реального лого актива или null. */
function asset_logo($ticker){
    global $TICKER_LOGO;
    $m = logo_manifest();
    $name = $TICKER_LOGO[$ticker] ?? (isset($m[$ticker]) ? $ticker : null);
    return ($name && isset($m[$name])) ? 'data/logos/' . $m[$name] : null;
}
/** Найти лого компании по тексту вопроса (с учётом склонений: «Газпроме»→Газпром). Возвращает URL или null. */
function company_logo_for($text, $includeManifest = false){
    static $stems = null;
    if ($stems === null) $stems = [
        // стем (в тексте) => имя в манифесте лого
        'apple'=>'Apple','nvidia'=>'Nvidia','tesla'=>'Tesla','microstrategy'=>'MicroStrategy','microsoft'=>'Microsoft',
        'coinbase'=>'Coinbase','robinhood'=>'Robinhood','amazon'=>'Amazon','disney'=>'Disney','google'=>'Google',
        'jpmorgan'=>'JPMorgan','mcdonald'=>"McDonald's",'netflix'=>'Netflix','meta'=>'Meta','coca-cola'=>'Coca-Cola','кока-кол'=>'Coca-Cola',
        'газпром'=>'Газпром','сбербанк'=>'Сбербанк','лукойл'=>'Лукойл','яндекс'=>'Яндекс','новатэк'=>'Новатэк',
        'норникел'=>'Норникель','роснефт'=>'Роснефть','втб'=>'ВТБ',
        'discord'=>'Discord','steam'=>'Steam','twitch'=>'Twitch',
        'anthropic'=>'Anthropic (Claude)','claude'=>'Anthropic (Claude)','openai'=>'OpenAI (GPT)',
    ];
    $m = logo_manifest();
    $t = mb_strtolower((string)$text, 'UTF-8');
    // собираем ВСЕ совпадения и берём САМОЕ ЛЕВОЕ в тексте (ключевое слово обычно идёт первым:
    // «курс юаня к рублю» → юань, «Газпром и Сбербанк» → Газпром).
    $best = null; $bestPos = PHP_INT_MAX;
    $consider = function($stem, $name) use (&$best, &$bestPos, $t, $m){
        if (!isset($m[$name])) return;
        if (preg_match('/(?<!\p{L})' . preg_quote($stem, '/') . '/iu', $t, $mm, PREG_OFFSET_CAPTURE)){
            $pos = $mm[0][1];
            if ($pos < $bestPos){ $bestPos = $pos; $best = 'data/logos/' . $m[$name]; }
        }
    };
    foreach ($stems as $stem=>$name) $consider($stem, $name);   // бренды
    // свои концепт-логотипы (data/logos/_keywords.json)
    $kw = jload(PS_DATA . '/logos/_keywords.json'); if (!is_array($kw)) $kw = [];
    foreach ($kw as $name=>$list){
        if (!is_array($list)) continue;
        foreach ($list as $stem){
            $stem = mb_strtolower(trim((string)$stem), 'UTF-8');
            if (mb_strlen($stem) >= 3) $consider($stem, $name);
        }
    }
    // страны/регионы/концепты из манифеста (Китай, Платина…): для ЗАГОЛОВКА вопроса НЕ подставляем
    // (генерится сцена). Но для ОТВЕТОВ ($includeManifest=true) — подставляем: «Китай» → лого Китая.
    if ($includeManifest){
        foreach ($m as $name=>$file){
            $stem = mb_strtolower((string)$name, 'UTF-8');
            if (mb_strlen($stem) >= 3) $consider($stem, $name);
        }
    }
    return $best;
}

/* хэш как в app.js (hashN): 32-битное signed переполнение, abs — для совпадения seed картинок */
function img_hashn($s){
    $s = (string)$s; $h = 0;
    $len = mb_strlen($s, 'UTF-8');
    for ($i=0; $i<$len; $i++){
        $code = mb_ord(mb_substr($s, $i, 1, 'UTF-8') ?: ' ', 'UTF-8');
        $h = $h * 31 + (int)$code;
        $h = $h & 0xFFFFFFFF;                       // как |0 в JS
        if ($h >= 0x80000000) $h -= 0x100000000;    // в signed 32-bit
    }
    return abs($h);
}
/* ПРЕ-ГЕНЕРАЦИЯ картинок событий в кэш (тот же ключ, что запросит браузер) — до $limit за проход.
   Вызывается фоновым воркером, чтобы к моменту показа картинки уже лежали готовыми (все варианты сразу). */
function img_prewarm($limit = 4){
    require_once __DIR__ . '/imglib.php';
    $cfg = jload(PS_DATA . '/config.json'); if (!is_array($cfg)) $cfg = [];
    if (!empty($cfg['paused'])) return 0;   // экстренный стоп — ничего не генерим
    $provider = $cfg['img_provider'] ?? 'pollinations';
    $tpl = $cfg['img_prompt'] ?? '{q}';
    $dir = PS_DATA . '/genimg'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $icfg = img_cfg();
    $events = jload(PS_DATA . '/events.json'); if (!is_array($events)) $events = [];
    $events = array_slice($events, 0, 40);   // только СВЕЖИЕ (не жжём квоту на все 500 старых)
    $made = 0;
    foreach ($events as $e){
        if ($made >= $limit) break;
        $tasks = [];  // [subject(image_en), seedSrc(id|label), size]
        if (empty($e['logo']) && !empty($e['image_en'])) $tasks[] = [$e['image_en'], $e['id'] ?? '', 512];
        if (($e['type'] ?? '') === 'open'){
            foreach (($e['options'] ?? []) as $o){ if (!empty($o['image_en'])) $tasks[] = [$o['image_en'], $o['label'] ?? '', 128]; }
        }
        foreach ($tasks as $t){
            if ($made >= $limit) break;
            $subject = trim((string)$t[0]);
            $prompt  = (strpos($tpl, '{q}') !== false) ? str_replace('{q}', $subject, $tpl) : ($subject . ', ' . $tpl);
            $w = (int)$t[2];
            $seed = img_hashn($t[1]) % 100000;
            $key = substr(md5($prompt . '|' . $w . '|' . $seed), 0, 20);   // БЕЗ провайдера: смена провайдера не сиротит кэш
            $web = $dir . '/w_' . $key . '.webp';
            if (is_file($web) && filesize($web) > 0) continue;   // уже в кэше
            $err = ''; $used = '';
            $raw = img_generate_chain($prompt, $w, $seed, $icfg, $err, $used);
            if ($raw !== false && $raw){
                $webp = img_to_webp($raw);
                if ($webp !== false){ file_put_contents($web, $webp); $made++; }
            } else {
                return $made;   // провайдеры недоступны — прекращаем проход
            }
        }
    }
    return $made;
}

/* СТЕЙДЖИНГ: события из pending.json публикуем в ленту ТОЛЬКО когда их картинка-заголовок готова.
   Так на карточках всегда есть картинка. Возвращает число опубликованных за проход. */
function promote_pending($limit = 3){
    require_once __DIR__ . '/imglib.php';
    $cfg = jload(PS_DATA . '/config.json'); if (!is_array($cfg)) $cfg = [];
    if (!empty($cfg['paused'])) return 0;
    $pf = PS_DATA . '/pending.json';
    $pend = jload($pf); if (!is_array($pend) || !$pend) return 0;
    $provider = $cfg['img_provider'] ?? 'pollinations';
    $tpl = $cfg['img_prompt'] ?? '{q}';
    $dir = PS_DATA . '/genimg'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $icfg = img_cfg();
    $max = (int)($GLOBALS['MAX_EVENTS'] ?? 500);
    // локальный генератор одной картинки по ключу кэша (как во фронтенде). Возвращает: 'cached'|'ok'|'fail'
    $genImg = function($subject, $seedSrc, $w) use ($provider, $tpl, $dir, $icfg){
        $subject = trim((string)$subject);
        if ($subject === '') return 'cached';
        $prompt = (strpos($tpl,'{q}')!==false) ? str_replace('{q}',$subject,$tpl) : ($subject.', '.$tpl);
        $seed = img_hashn($seedSrc) % 100000;
        $key = substr(md5($prompt.'|'.$w.'|'.$seed), 0, 20);   // БЕЗ провайдера
        $web = $dir.'/w_'.$key.'.webp';
        if (is_file($web) && filesize($web) > 0) return 'cached';
        $err=''; $used='';
        $raw = img_generate_chain($prompt, $w, $seed, $icfg, $err, $used);
        if ($raw === false || !$raw) return 'fail';
        $wp = img_to_webp($raw);
        if ($wp === false) return 'fail';
        file_put_contents($web, $wp);
        return 'ok';
    };
    $publish = function($e) use ($max){
        unset($e['staged_at'], $e['gen_attempts']);
        $events = jload(PS_DATA.'/events.json'); if (!is_array($events)) $events = [];
        array_unshift($events, $e);
        if (count($events) > $max) $events = array_slice($events, 0, $max);
        jsave(PS_DATA.'/events.json', $events);
    };
    // РАСПИСАНИЕ ВЫПУСКА: не чаще раза в interval, и не больше per_run за выпуск (FIFO — кто дольше ждёт).
    $interval = max(10, (int)($cfg['auto']['interval'] ?? 120));
    $perRun   = max(1,  (int)($cfg['auto']['per_run']  ?? 1));
    $sf = PS_DATA . '/publish_state.json';
    $ps = jload($sf); $lastPub = is_array($ps) ? (int)($ps['last'] ?? 0) : 0;
    $now = time();
    if ($lastPub <= 0) $lastPub = $now - $interval;   // первый запуск — разрешаем сразу один слот
    // $lastPub = КУРСОР РАСПИСАНИЯ: время слота последнего выпуска. Выпуски «положены» в моменты
    // lastPub+interval, lastPub+2*interval, ... Если картинки долго генерились и слоты прошли
    // «пустыми» — они копятся как ДОЛГ: как только события готовы, выпускаем сразу столько,
    // сколько слотов просрочено. Потолок, чтобы после долгого простоя не выкатить всё лавиной.
    $CATCHUP_MAX = 5;
    if ($now - $lastPub > $CATCHUP_MAX * $interval) $lastPub = $now - $CATCHUP_MAX * $interval;   // потолок долга
    $dueSlots = ($now - $lastPub >= $interval) ? intdiv($now - $lastPub, $interval) : 0;
    $releaseQuota = $dueSlots * $perRun;   // сколько готовых событий можно выпустить сейчас (догон пропущенных слотов)

    $budget = max(1, (int)$limit);   // сколько картинок генерим за этот проход (нагрузка)
    $promoted = 0; $publishedKeys = [];
    // идём от САМЫХ СТАРЫХ к новым (в pending новейшие сверху) — очередь FIFO
    foreach (array_reverse(array_keys($pend)) as $i){
        $e = $pend[$i];
        // все нужные картинки: заголовок + варианты
        $tasks = [];
        if (!empty($e['image_en'])) $tasks[] = [$e['image_en'], $e['id'] ?? '', 512];
        if (($e['type'] ?? '') === 'open'){
            foreach (($e['options'] ?? []) as $o){ if (!empty($o['image_en'])) $tasks[] = [$o['image_en'], $o['label'] ?? '', 128]; }
        }
        $allReady = true;
        foreach ($tasks as $t){
            $subject = trim((string)$t[0]);
            if ($subject === '') continue;
            $prompt = (strpos($tpl,'{q}')!==false) ? str_replace('{q}',$subject,$tpl) : ($subject.', '.$tpl);
            $seed = img_hashn($t[1]) % 100000;
            $key = substr(md5($prompt.'|'.$t[2].'|'.$seed),0,20);   // БЕЗ провайдера
            $web = $dir.'/w_'.$key.'.webp';
            if (is_file($web) && filesize($web) > 0) continue;   // уже готова
            if ($budget <= 0){ $allReady = false; break; }        // бюджет генерации на проход исчерпан
            $budget--;
            $res = $genImg($t[0], $t[1], $t[2]);
            if ($res === 'fail'){ $allReady = false; break; }     // не удалось — держим в стейджинге
        }
        // публикуем ТОЛЬКО если все картинки готовы И есть слот выпуска (расписание)
        if ($allReady && $releaseQuota > 0){
            $publish($e); $publishedKeys[$i] = true; $releaseQuota--; $promoted++;
        }
    }
    if ($promoted > 0){
        // сдвигаем курсор на число ПОТРАЧЕННЫХ слотов, а НЕ на now: если выпустили меньше,
        // чем было просрочено (не все события успели дозреть) — остаток слотов сохраняется как долг.
        $slotsUsed = (int)ceil($promoted / $perRun);
        $lastPub = min(time(), $lastPub + $slotsUsed * $interval);
        jsave($sf, ['last' => $lastPub]);
    }
    // пересобираем стейджинг без опубликованных (порядок сохраняем)
    $still = [];
    foreach ($pend as $i=>$e){ if (empty($publishedKeys[$i])) $still[] = $e; }
    jsave($pf, $still);
    return $promoted;
}

/* Ответ — просто число/диапазон? («выше 100», «90–95 ₽», «менее 30%»). Для таких — без картинок/лого. */
function is_numeric_answer($label){
    $s = mb_strtolower(trim((string)$label), 'UTF-8');
    if ($s === '' || !preg_match('/\d/u', $s)) return false;
    $s = preg_replace('/[0-9.,%₽$€–—\-\s()]/u', '', $s);
    foreach (['выше','ниже','более','менее','свыше','около','от','до','пунктов','пункта','пункт','пп','бп',
              'руб','рубля','рублей','доллар','доллара','долларов','евро','процент','процента','процентов',
              'тыс','млн','млрд','раз'] as $w){ $s = str_replace($w, '', $s); }
    return trim($s) === '';
}
/** Картинка актива: реальное лого, иначе рисованный бейдж. */
function asset_image($ticker, $bg){
    $lg = asset_logo($ticker);
    return $lg ?: img_badge(asset_display($ticker), $bg);   // нет лого -> бейдж с ПОЛНЫМ названием
}
/* читаемое название актива для ПОДПИСЕЙ (тикер остаётся для лого и авто-исхода) */
$ASSET_NAME = [
    'AAPL'=>'Apple','TSLA'=>'Tesla','NVDA'=>'Nvidia','MSFT'=>'Microsoft','AMZN'=>'Amazon','GOOGL'=>'Google','META'=>'Meta','NFLX'=>'Netflix',
    'BTC'=>'Bitcoin','ETH'=>'Ethereum','SOL'=>'Solana','XRP'=>'XRP','DOGE'=>'Dogecoin','TON'=>'Toncoin','BNB'=>'BNB','ADA'=>'Cardano','TRX'=>'Tron','AVAX'=>'Avalanche',
];
function asset_display($ticker){ global $ASSET_NAME; return $ASSET_NAME[$ticker] ?? $ticker; }

/** Найти запись актива в пуле (для цвета), либо дефолт. */
function pool_entry($cat, $ticker){
    global $POOLS;
    if (isset($POOLS[$cat])) foreach ($POOLS[$cat] as $x){ if ($x['t'] === $ticker) return $x; }
    return ['t'=>$ticker, 'c'=>'#2b3550'];
}
/** Событие «вверх/вниз» для КОНКРЕТНОГО актива (крипта — реальная цена + авто-исход). */
function gen_asset_specific($cat, $ticker, $tf){
    $a = pool_entry($cat, $ticker);
    $e = ev_base($cat, 'closed', $tf); $e['kind'] = 'updown';
    $e['image'] = asset_image($a['t'], $a['c']);
    $price = asset_price($cat, $a['t']); $up = pct(35,65);
    if ($price !== null){
        $e['question']  = "Будет ли " . asset_display($a['t']) . " выше " . fmt_price($price) . " через {$e['timeframe_label']}?";
        $e['symbol']    = $a['t']; $e['ref_price'] = $price; $e['metric'] = 'above';
        $e['options'] = [ ['label'=>'ДА','price'=>$up], ['label'=>'НЕТ','price'=>100-$up] ];
    } else {
        $e['question']  = "«" . asset_display($a['t']) . "» вверх или вниз за {$e['timeframe_label']}?";
        $e['options'] = [ ['label'=>'Вверх','price'=>$up], ['label'=>'Вниз','price'=>100-$up] ];
    }
    return $e;
}
/* хранилище детальной пер-активной генерации */
function assetgen_load(){ $j = jload(PS_DATA . '/asset_gen.json'); return is_array($j) ? $j : []; }
function assetgen_save($d){ jsave(PS_DATA . '/asset_gen.json', $d); }

/** Сколько вариантов ответа в код-прогнозах рынков. Если для интервала $tf задано отдельно — берём его, иначе общее. */
function market_opts($tf = null){
    $c = jload(PS_DATA . '/config.json');
    if ($tf !== null){
        $iv = is_array($c['market_opts_iv'] ?? null) ? $c['market_opts_iv'] : [];
        if (isset($iv[$tf])){ $n = (int)$iv[$tf]; if ($n>=2 && $n<=9) return $n; }
    }
    return max(2, min(9, (int)($c['market_options'] ?? 5)));
}

/* ---------- flexible timeframe ---------- */
/** "45s","5m","1h","2d","1w","1M","1y" and combos "1h30m". Plain number = seconds. */
function parse_timeframe($tf){
    $tf = trim((string)$tf);
    if ($tf === '') return 3600;
    if (preg_match('/^\d+$/', $tf)) return max(1, (int)$tf);
    $units = ['s'=>1,'m'=>60,'h'=>3600,'d'=>86400,'w'=>604800,'M'=>2592000,'y'=>31536000];
    $total = 0; $ok = false;
    if (preg_match_all('/(\d+)\s*([smhdwMy])/', $tf, $mm, PREG_SET_ORDER)){
        foreach ($mm as $m){ $total += (int)$m[1] * $units[$m[2]]; $ok = true; }
    }
    return $ok ? max(1, $total) : 3600;
}
function tf_label($sec){
    $sec = (int)$sec;
    if ($sec < 60) return $sec . ' сек';
    $units = [['год',31536000],['мес',2592000],['нед',604800],['дн',86400],['ч',3600],['мин',60],['сек',1]];
    $rem = $sec; $parts = [];
    foreach ($units as $u){
        if ($rem >= $u[1]){ $q = intdiv($rem,$u[1]); $rem -= $q*$u[1]; $parts[] = $q.' '.$u[0]; }
        if (count($parts) >= 2) break;
    }
    return implode(' ', $parts);
}

/* ---------- categories & pools ---------- */
/* full ordered category list (markets + geopolitics + analytics themes) — drives the admin dropdowns */
/* type: 'code' = генерится шаблонами/расписаниями (рынки, битвы); 'ai' = генерится ИИ из новостей */
$CATS = [
    ['code'=>'crypto','label'=>'Крипта','group'=>'Рынки','type'=>'code'],
    ['code'=>'stocks','label'=>'Акции','group'=>'Рынки','type'=>'code'],
    ['code'=>'currency','label'=>'Валюты','group'=>'Рынки','type'=>'code'],
    ['code'=>'indexes','label'=>'Индексы','group'=>'Рынки','type'=>'code'],
    ['code'=>'metals','label'=>'Металлы','group'=>'Рынки','type'=>'code'],
    ['code'=>'commodities','label'=>'Сырьё','group'=>'Рынки','type'=>'code'],
    ['code'=>'battles_global','label'=>'Битвы: Глобальные','group'=>'Битвы','type'=>'code'],
    ['code'=>'battles_ru_world','label'=>'Битвы: Россия или мир','group'=>'Битвы','type'=>'code'],
    ['code'=>'war_ru_ua','label'=>'Война России и Украины','group'=>'Геополитика','type'=>'ai'],
    ['code'=>'frontline','label'=>'Линия фронта','group'=>'Геополитика','type'=>'ai'],
    ['code'=>'crypto_news','label'=>'Криптовалюты','group'=>'Аналитика','type'=>'ai'],
    ['code'=>'world_geo','label'=>'Мир · Геополитика','group'=>'Аналитика','type'=>'ai'],
    ['code'=>'world_tech','label'=>'Мир · Технологии','group'=>'Аналитика','type'=>'ai'],
    ['code'=>'world_econ','label'=>'Мир · Экономика','group'=>'Аналитика','type'=>'ai'],
    ['code'=>'ru_geo','label'=>'Россия · Геополитика','group'=>'Аналитика','type'=>'ai'],
    ['code'=>'ru_tech','label'=>'Россия · Технологии','group'=>'Аналитика','type'=>'ai'],
    ['code'=>'ru_econ','label'=>'Россия · Экономика','group'=>'Аналитика','type'=>'ai'],
    ['code'=>'ru_internal','label'=>'Политика России (внутр.)','group'=>'Аналитика','type'=>'ai'],
    ['code'=>'putin','label'=>'Путин','group'=>'Аналитика','type'=>'ai'],
];
$CAT_LABEL = [];
foreach ($CATS as $c){ $CAT_LABEL[$c['code']] = $c['label']; }

/* custom categories (added from the admin, persisted in data/categories.json) */
$CATSFILE = PS_DATA . '/categories.json';
$CUSTOM = jload($CATSFILE);
$CUSTOM_BY_CODE = [];
foreach ($CUSTOM as $cc){
    if (empty($cc['code'])) continue;
    $CATS[] = ['code'=>$cc['code'], 'label'=>$cc['label'], 'group'=>$cc['group'] ?? 'Свои', 'type'=>$cc['type'] ?? 'ai'];
    $CAT_LABEL[$cc['code']] = $cc['label'];
    $CUSTOM_BY_CODE[$cc['code']] = $cc;
}

$POOLS = [
    'crypto' => [
        ['t'=>'BTC','c'=>'#f7931a'],['t'=>'ETH','c'=>'#627eea'],['t'=>'SOL','c'=>'#14f195'],
        ['t'=>'XRP','c'=>'#3b4650'],['t'=>'DOGE','c'=>'#c2a633'],['t'=>'TON','c'=>'#0098ea'],
        ['t'=>'BNB','c'=>'#d9a400'],['t'=>'ADA','c'=>'#0033ad'],['t'=>'TRX','c'=>'#e50914'],['t'=>'AVAX','c'=>'#e84142'],
    ],
    'stocks' => [
        ['t'=>'AAPL','c'=>'#555'],['t'=>'TSLA','c'=>'#cc0000'],['t'=>'NVDA','c'=>'#76b900'],
        ['t'=>'MSFT','c'=>'#0067b8'],['t'=>'AMZN','c'=>'#ff9900'],['t'=>'GOOGL','c'=>'#4285f4'],
        ['t'=>'META','c'=>'#0866ff'],['t'=>'NFLX','c'=>'#b1060f'],
    ],
    'currency' => [
        ['t'=>'EUR','c'=>'#1b3a8f'],['t'=>'USD','c'=>'#2e7d32'],['t'=>'RUB','c'=>'#7a1f1f'],
        ['t'=>'UAH','c'=>'#0057b7'],['t'=>'GBP','c'=>'#4a148c'],['t'=>'CNY','c'=>'#b71c1c'],['t'=>'JPY','c'=>'#bc002d'],
    ],
    'metals' => [
        ['t'=>'Золото','c'=>'#c9a227'],['t'=>'Серебро','c'=>'#9aa4b2'],['t'=>'Платина','c'=>'#6b7b8c'],
        ['t'=>'Палладий','c'=>'#8a7f6b'],['t'=>'Медь','c'=>'#b8642f'],
    ],
    'indexes' => [
        ['t'=>'S&P500','c'=>'#1565c0'],['t'=>'NASDAQ','c'=>'#00897b'],['t'=>'DOW','c'=>'#5e35b1'],
        ['t'=>'DAX','c'=>'#455a64'],['t'=>'FTSE','c'=>'#6d4c41'],['t'=>'Nikkei','c'=>'#c62828'],
    ],
    'commodities' => [
        ['t'=>'Нефть','c'=>'#33383d'],['t'=>'Газ','c'=>'#0277bd'],['t'=>'Пшеница','c'=>'#c9a227'],
        ['t'=>'Кукуруза','c'=>'#f9a825'],['t'=>'Кофе','c'=>'#5d4037'],['t'=>'Сахар','c'=>'#90a4ae'],
    ],
];
$RACE_Q = [
    'crypto'=>'Какая монета вырастет больше за {TF}?',
    'stocks'=>'Какая акция вырастет больше за {TF}?',
    'currency'=>'Какая валюта укрепится сильнее за {TF}?',
    'metals'=>'Какой металл вырастет больше за {TF}?',
    'indexes'=>'Какой индекс вырастет больше за {TF}?',
    'commodities'=>'Какое сырьё подорожает больше за {TF}?',
];

/* non-market themes (geopolitics + analytics). Each: badge/bg + closed[] + open[{q,opts}] */
$THEMES = [
    'war_ru_ua' => ['badge'=>'ВОЙНА','bg'=>'#3a1818',
        'closed'=>['Закончится ли война России и Украины за {TF}?','Будет ли перемирие за {TF}?','Начнутся ли переговоры за {TF}?','Введут ли новые санкции за {TF}?'],
        'open'=>[['q'=>'Какой сценарий вероятнее за {TF}?','opts'=>['Эскалация','Заморозка','Переговоры','Без изменений']]]],
    'frontline' => ['badge'=>'ФРОНТ','bg'=>'#3a2418',
        'closed'=>['Существенно ли сместится линия фронта за {TF}?','Возьмут ли ключевой город за {TF}?'],
        'open'=>[['q'=>'Какое направление продвинется больше за {TF}?','opts'=>['Восточное','Южное','Северное','Запорожское','Купянское']]]],
    'battles_global' => ['badge'=>'БИТВЫ','bg'=>'#2a1a3a',
        'closed'=>['Вспыхнет ли новый крупный конфликт за {TF}?'],
        'open'=>[['q'=>'Где будет крупнейший конфликт за {TF}?','opts'=>['Европа','Ближний Восток','Азия','Африка']]]],
    'battles_ru_world' => ['badge'=>'РФ/МИР','bg'=>'#2a1a3a',
        'closed'=>['Усилит ли Россия влияние в мире за {TF}?'],
        'open'=>[['q'=>'Кто усилит позиции за {TF}?','opts'=>['Россия','Запад','Китай','Статус-кво']]]],
    'world_geo' => ['badge'=>'МИР','bg'=>'#18303a',
        'closed'=>['Обострится ли мировая обстановка за {TF}?'],
        'open'=>[['q'=>'Главная точка напряжения за {TF}?','opts'=>['Европа','Ближний Восток','Азия','Африка']]]],
    'world_tech' => ['badge'=>'ТЕХ','bg'=>'#18303a',
        'closed'=>['Будет ли крупный техно-прорыв за {TF}?'],
        'open'=>[['q'=>'Что выстрелит в технологиях за {TF}?','opts'=>['ИИ','Полупроводники','Биотех','Космос','Квантовые']]]],
    'world_econ' => ['badge'=>'ЭКО','bg'=>'#18303a',
        'closed'=>['Будет ли рецессия в мире за {TF}?'],
        'open'=>[['q'=>'Что будет с мировой экономикой за {TF}?','opts'=>['Рост','Стагнация','Рецессия','Кризис']]]],
    'ru_geo' => ['badge'=>'РФ ГЕО','bg'=>'#2a1f14',
        'closed'=>['Введут ли новые санкции против РФ за {TF}?'],
        'open'=>[['q'=>'Ключевой внешний вектор РФ за {TF}?','opts'=>['Запад','Восток','Глобальный Юг','Изоляция']]]],
    'ru_tech' => ['badge'=>'РФ ТЕХ','bg'=>'#2a1f14',
        'closed'=>['Будет ли крупный техно-проект в РФ за {TF}?'],
        'open'=>[['q'=>'Фокус технологий РФ за {TF}?','opts'=>['Импортозамещение','ИИ','Оборонка','Энергетика']]]],
    'ru_econ' => ['badge'=>'РФ ЭКО','bg'=>'#2a1f14',
        'closed'=>['Изменит ли ЦБ ключевую ставку за {TF}?'],
        'open'=>[['q'=>'Рубль за {TF}?','opts'=>['Укрепится','Ослабнет','Без изменений']]]],
    'ru_internal' => ['badge'=>'РФ ПОЛ','bg'=>'#1f3d2b',
        'closed'=>['Произойдут ли кадровые перестановки за {TF}?'],
        'open'=>[['q'=>'Внутренняя политика РФ за {TF}?','opts'=>['Стабильность','Реформы','Обострение']]]],
    'putin' => ['badge'=>'ПУТИН','bg'=>'#1f3d2b',
        'closed'=>['Выступит ли Путин с важным заявлением за {TF}?'],
        'open'=>[['q'=>'Ключевое решение Путина за {TF}?','opts'=>['Внешняя политика','Экономика','Оборона','Кадры']]]],
];

/* ---------- event builders ---------- */
function ev_base($cat,$type,$tf){
    global $CAT_LABEL;
    $sec = parse_timeframe($tf);
    return [
        'id'=>rid(), 'type'=>$type, 'category'=>$cat, 'category_label'=>$CAT_LABEL[$cat] ?? $cat,
        'timeframe'=>$tf, 'timeframe_label'=>tf_label($sec),
        'created_at'=>date('c'), 'resolves_at'=>date('c', time()+$sec), 'source'=>'auto',
    ];
}

/* CLOSED: asset up or down (no answer images). Для крипты — РЕАЛЬНАЯ цена + авто-проверка. */
function gen_asset_updown($pool,$cat,$tf){
    $a = pick_rotating($pool,$cat); $e = ev_base($cat,'closed',$tf); $e['kind'] = 'updown';
    $e['image'] = asset_image($a['t'], $a['c']);
    $price = asset_price($cat, $a['t']);
    $up = pct(35,65);
    if ($price !== null){   // реальная котировка: «будет ли выше текущей цены через срок»
        $e['question']  = "Будет ли " . asset_display($a['t']) . " выше " . fmt_price($price) . " через {$e['timeframe_label']}?";
        $e['symbol']    = $a['t'];
        $e['ref_price'] = $price;   // цена на момент создания — база сравнения
        $e['metric']    = 'above';
        $e['options'] = [ ['label'=>'ДА','price'=>$up], ['label'=>'НЕТ','price'=>100-$up] ];
    } else {
        $e['question'] = "«{$a['t']}» вверх или вниз за {$e['timeframe_label']}?";
        $e['options'] = [ ['label'=>'Вверх','price'=>$up], ['label'=>'Вниз','price'=>100-$up] ];
    }
    return $e;
}
/* CLOSED: price ranges, YES % per range (no answer images) */
function gen_asset_range($pool,$cat,$tf){
    $a = pick_rotating($pool,$cat); $e = ev_base($cat,'closed',$tf); $e['kind'] = 'range';
    $e['question'] = "Цена " . asset_display($a['t']) . " к концу периода ({$e['timeframe_label']})?";
    $e['image'] = asset_image($a['t'], $a['c']);
    $n = market_opts($tf); $top = intdiv($n,2); $bot = $top-($n-1);   // ровно N диапазонов (по интервалу или общее)
    $base = pct(50,9000)/10; $step = round($base*0.02, 2); $ranges=[];
    for ($i=$top;$i>=$bot;$i--){
        $lo = round($base+($i-0.5)*$step,2); $hi = round($base+($i+0.5)*$step,2);
        $label = ($i==$top)?"выше {$lo}":(($i==$bot)?"ниже {$hi}":"{$lo} – {$hi}");
        $ranges[] = ['label'=>$label, 'price'=>max(2, 40-abs($i)*8)];
    }
    $e['options'] = $ranges; return $e;
}
/* CLOSED: A vs B (no answer images) */
function gen_versus($pool,$cat,$tf){
    $a = pick($pool); do { $b = pick($pool); } while ($b['t']===$a['t']);
    $e = ev_base($cat,'closed',$tf); $e['kind'] = 'versus';
    $e['question'] = "Что будет выше через {$e['timeframe_label']} — " . asset_display($a['t']) . " или " . asset_display($b['t']) . "?";
    $e['image'] = img_badge($a['t'].'/'.$b['t'], '#243b53');
    $x = pct(40,60);
    $e['options'] = [ ['label'=>asset_display($a['t']),'price'=>$x], ['label'=>asset_display($b['t']),'price'=>100-$x] ];
    return $e;
}
/* OPEN: race — options WITH images */
function gen_race($pool,$cat,$tf,$q){
    $e = ev_base($cat,'open',$tf); $e['kind'] = 'race';
    $e['question'] = str_replace('{TF}', $e['timeframe_label'], $q);
    $e['image'] = img_badge('РЕЙС', '#3a2b5a');
    $items = $pool; shuffle($items); $items = array_slice($items, 0, min(market_opts($tf), count($items))); $opts=[];   // N вариантов (по интервалу или общее)
    foreach ($items as $it){ $opts[] = ['label'=>asset_display($it['t']),'price'=>pct(5,30),'image'=>asset_image($it['t'],$it['c'])]; }
    $e['options'] = $opts; return $e;
}
/* generic OPEN template (each option WITH image) */
function gen_open_tpl($cat,$tf,$q,$opts,$badge,$bg){
    $e = ev_base($cat,'open',$tf);
    $e['question'] = str_replace('{TF}', $e['timeframe_label'], $q);
    $e['image'] = img_badge($badge, $bg);
    $o = [];
    foreach ($opts as $label){ $o[] = ['label'=>$label,'price'=>pct(8,45),'image'=>img_badge($label,'#2b3550')]; }
    $e['options'] = $o; return $e;
}
/* generic CLOSED yes/no template (no answer images) */
function gen_yn_tpl($cat,$tf,$q,$badge,$bg){
    $e = ev_base($cat,'closed',$tf);
    $e['question'] = str_replace('{TF}', $e['timeframe_label'], $q);
    $e['image'] = img_badge($badge, $bg);
    $yes = pct(20,70);
    $e['options'] = [ ['label'=>'ДА','price'=>$yes], ['label'=>'НЕТ','price'=>100-$yes] ];
    return $e;
}

/* ---------- one event from (category,type,timeframe) ---------- */
function gen_one($category,$type,$tf){
    global $POOLS,$RACE_Q,$THEMES,$CUSTOM_BY_CODE;
    if (isset($POOLS[$category])){
        $pool = $POOLS[$category];
        if ($type==='open') return gen_race($pool,$category,$tf,$RACE_Q[$category]);
        if ($category==='crypto') return gen_asset_updown($pool,$category,$tf);   // реальная цена + авто-проверка
        $roll = random_int(0,2);
        if ($roll===0) return gen_asset_updown($pool,$category,$tf);
        if ($roll===1) return gen_asset_range($pool,$category,$tf);
        return gen_versus($pool,$category,$tf);
    }
    if (isset($THEMES[$category])){
        $t = $THEMES[$category]; $wantOpen = ($type==='open');
        if ($wantOpen && !empty($t['open'])){ $tp=pick($t['open']); return gen_open_tpl($category,$tf,$tp['q'],$tp['opts'],$t['badge'],$t['bg']); }
        if (!$wantOpen && !empty($t['closed'])){ return gen_yn_tpl($category,$tf,pick($t['closed']),$t['badge'],$t['bg']); }
        if (!empty($t['open'])){ $tp=pick($t['open']); return gen_open_tpl($category,$tf,$tp['q'],$tp['opts'],$t['badge'],$t['bg']); }
        return gen_yn_tpl($category,$tf,pick($t['closed']),$t['badge'],$t['bg']);
    }
    if (!empty($CUSTOM_BY_CODE[$category])){
        $c = $CUSTOM_BY_CODE[$category];
        $opts = $c['options'] ?? [];
        $badge = mb_substr($c['label'], 0, 5, 'UTF-8');
        $q = $c['question'] ?: ($opts ? 'Что будет за {TF}?' : 'Произойдёт ли событие за {TF}?');
        if (!empty($opts)) return gen_open_tpl($category,$tf,$q,$opts,$badge,'#2b3550');
        return gen_yn_tpl($category,$tf,$q,$badge,'#2b3550');
    }
    return gen_asset_updown($POOLS['crypto'],'crypto',$tf);
}

/* ---------- presets (ready buttons) ---------- */
$PRESETS = [
    'crypto_5m'    => ['title'=>'Крипта · 5 минут',      'category'=>'crypto',     'type'=>'closed','timeframe'=>'5m', 'count'=>6],
    'crypto_1h'    => ['title'=>'Крипта · 1 час',        'category'=>'crypto',     'type'=>'closed','timeframe'=>'1h', 'count'=>6],
    'crypto_1d'    => ['title'=>'Крипта · день',         'category'=>'crypto',     'type'=>'closed','timeframe'=>'1d', 'count'=>5],
    'crypto_race'  => ['title'=>'Крипта · гонка монет',  'category'=>'crypto',     'type'=>'open',  'timeframe'=>'1d', 'count'=>2],
    'stocks_1h'    => ['title'=>'Акции · 1 час',         'category'=>'stocks',     'type'=>'closed','timeframe'=>'1h', 'count'=>6],
    'stocks_1d'    => ['title'=>'Акции · день',          'category'=>'stocks',     'type'=>'closed','timeframe'=>'1d', 'count'=>6],
    'currency_1h'  => ['title'=>'Валюты · 1 час',        'category'=>'currency',   'type'=>'closed','timeframe'=>'1h', 'count'=>5],
    'currency_1d'  => ['title'=>'Валюты · день',         'category'=>'currency',   'type'=>'closed','timeframe'=>'1d', 'count'=>5],
    'indexes_1d'   => ['title'=>'Индексы · день',        'category'=>'indexes',    'type'=>'closed','timeframe'=>'1d', 'count'=>5],
    'metals_1d'    => ['title'=>'Металлы · день',        'category'=>'metals',     'type'=>'closed','timeframe'=>'1d', 'count'=>4],
    'commod_1d'    => ['title'=>'Сырьё · день',          'category'=>'commodities','type'=>'closed','timeframe'=>'1d', 'count'=>4],
    'geo_open'     => ['title'=>'Геополитика · открытые','category'=>'geopolitics','type'=>'open',  'timeframe'=>'1w', 'count'=>3],
    'geo_yn'       => ['title'=>'Геополитика · да/нет',  'category'=>'geopolitics','type'=>'closed','timeframe'=>'1M', 'count'=>4],
    'politics_open'=> ['title'=>'Политика · открытые',   'category'=>'politics',   'type'=>'open',  'timeframe'=>'1M', 'count'=>4],
];

/* mandatory timeframes for the admin quick-buttons */
$TF_HINTS = [
    ['v'=>'5m','l'=>'5 мин'], ['v'=>'15m','l'=>'15 мин'], ['v'=>'30m','l'=>'30 мин'],
    ['v'=>'1h','l'=>'1 час'], ['v'=>'4h','l'=>'4 часа'], ['v'=>'1d','l'=>'День'],
    ['v'=>'1w','l'=>'Неделя'], ['v'=>'1M','l'=>'Месяц'], ['v'=>'1y','l'=>'Год'],
];

/* ---------- router (skipped when this file is included as a library) ---------- */
if (defined('GEN_INCLUDE')) return;
$action = $_REQUEST['action'] ?? 'list';

if ($action==='docx_batches'){   // список .docx-пачек из data/docx (вопрос + варианты)
    require_once __DIR__ . '/docx_import.php';
    $b = docx_batches();
    // В список отдаём только счётчики — сами вопросы поедут при импорте пачки.
    $out = array_map(fn($x)=>['file'=>$x['file'],'name'=>$x['name'],'count'=>$x['count']], $b);
    echo json_encode(['ok'=>true,'batches'=>$out], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='docx_import'){    // импорт одной пачки в СВОЁ хранилище (не в ленту сайта)
    require_once __DIR__ . '/docx_import.php';
    $file = basename((string)($_REQUEST['file'] ?? ''));       // basename: не выпускаем путь за data/docx
    $path = DOCX_DIR . '/' . $file;
    if ($file === '' || !is_file($path)) {
        echo json_encode(['ok'=>false,'error'=>'Пачка не найдена'], JSON_UNESCAPED_UNICODE); exit;
    }

    $questions = docx_parse($path);
    if (!$questions) { echo json_encode(['ok'=>false,'error'=>'В пачке нет вопросов'], JSON_UNESCAPED_UNICODE); exit; }

    $store = docx_store_load();
    // Повторный импорт той же пачки не должен плодить дубли: ключ — сам текст вопроса.
    $seen = [];
    foreach ($store as $e) { if (!empty($e['question'])) $seen[$e['question']] = true; }

    $now = time(); $added = 0; $skipped = 0; $noArt = 0;
    foreach ($questions as $q) {
        if (isset($seen[$q['question']])) { $skipped++; continue; }

        // Сюжеты картинок (image_en) — короткие описания на английском: одно на
        // вопрос, по одному на вариант. Их потом рисует выбранный провайдер
        // (сейчас cloudflare). Без image_en картинки не будет.
        $art = docx_art_prompts($q['question'], $q['options']);
        if (!$art) $noArt++;

        $opts = [];
        foreach ($q['options'] as $i => $label) {
            $o = ['label' => $label];
            if (!empty($art['options'][$i])) $o['image_en'] = $art['options'][$i];
            $opts[] = $o;
        }

        $row = [
            'id'         => substr(md5($file.'|'.$q['question']), 0, 12),
            'batch'      => $file,
            'batch_name' => preg_replace('~\.docx$~ui', '', $file),
            'created_at' => gmdate('c', $now),
            'question'   => $q['question'],
            'options'    => $opts,
        ];
        if (!empty($art['event'])) $row['image_en'] = $art['event'];
        $store[] = $row;
        $seen[$q['question']] = true; $added++;
    }
    docx_store_save($store);
    echo json_encode(['ok'=>true,'added'=>$added,'skipped'=>$skipped,'no_art'=>$noArt,'total'=>count($store)], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='docx_list'){      // всё, что импортировано (для страницы)
    require_once __DIR__ . '/docx_import.php';
    echo json_encode(['ok'=>true,'items'=>array_values(docx_store_load())], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='docx_delete'){    // удалить один вопрос или всю пачку
    require_once __DIR__ . '/docx_import.php';
    $id    = (string)($_REQUEST['id'] ?? '');
    $batch = (string)($_REQUEST['batch'] ?? '');
    $store = docx_store_load();
    $before = count($store);
    $store = array_values(array_filter($store, function($e) use ($id, $batch){
        if ($id !== '')    return ($e['id'] ?? '') !== $id;
        if ($batch !== '') return ($e['batch'] ?? '') !== $batch;
        return true;
    }));
    docx_store_save($store);
    echo json_encode(['ok'=>true,'removed'=>$before-count($store),'total'=>count($store)], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='docx_gallery'){   // набор настоящих фото человека — листать и выбирать
    require_once __DIR__ . '/docx_import.php';
    require_once __DIR__ . '/person_gallery.php';
    $id  = (string)($_REQUEST['id'] ?? '');
    $opt = $_REQUEST['opt'] ?? '';

    $store = docx_store_load();
    $idx = null;
    foreach ($store as $i => $e) { if (($e['id'] ?? '') === $id) { $idx = $i; break; } }
    if ($idx === null) { echo json_encode(['ok'=>false,'error'=>'Вопрос не найден'], JSON_UNESCAPED_UNICODE); exit; }

    $text = $store[$idx]['question'];
    if ($opt !== '') $text .= ' ' . ($store[$idx]['options'][(int)$opt]['label'] ?? '');

    $sets = gallery_for($text);
    if (!$sets) { echo json_encode(['ok'=>false,'error'=>'В вопросе нет известного человека'], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['ok'=>true,'sets'=>$sets], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='docx_setphoto'){  // поставить выбранное из галереи фото
    require_once __DIR__ . '/docx_import.php';
    $id  = (string)($_REQUEST['id'] ?? '');
    $opt = $_REQUEST['opt'] ?? '';
    $url = (string)($_REQUEST['url'] ?? '');
    // Ставим только то, что лежит в нашей папке портретов.
    if (!preg_match('~^data/persons/[\w.]+\.webp$~', $url)) {
        echo json_encode(['ok'=>false,'error'=>'Неизвестный адрес фото'], JSON_UNESCAPED_UNICODE); exit;
    }

    $store = docx_store_load();
    $idx = null;
    foreach ($store as $i => $e) { if (($e['id'] ?? '') === $id) { $idx = $i; break; } }
    if ($idx === null) { echo json_encode(['ok'=>false,'error'=>'Вопрос не найден'], JSON_UNESCAPED_UNICODE); exit; }

    if ($opt !== '') $store[$idx]['options'][(int)$opt]['image_url'] = $url;
    else             $store[$idx]['image_url'] = $url;
    docx_store_save($store);
    echo json_encode(['ok'=>true,'url'=>$url], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='docx_photo'){     // подставить настоящее фото человека вместо рисованного
    require_once __DIR__ . '/docx_import.php';
    require_once __DIR__ . '/person_photo.php';
    $id  = (string)($_REQUEST['id'] ?? '');
    $opt = $_REQUEST['opt'] ?? '';

    $store = docx_store_load();
    $idx = null;
    foreach ($store as $i => $e) { if (($e['id'] ?? '') === $id) { $idx = $i; break; } }
    if ($idx === null) { echo json_encode(['ok'=>false,'error'=>'Вопрос не найден'], JSON_UNESCAPED_UNICODE); exit; }

    // Ищем имя и в тексте вопроса, и в подписи варианта: «Орбан» может стоять
    // только в вопросе, а вариантом быть «В октябре».
    $text = $store[$idx]['question'];
    if ($opt !== '') $text .= ' ' . ($store[$idx]['options'][(int)$opt]['label'] ?? '');

    $url = person_photo_for($text);
    if (!$url) { echo json_encode(['ok'=>false,'error'=>'В вопросе нет известного человека'], JSON_UNESCAPED_UNICODE); exit; }

    if ($opt !== '') $store[$idx]['options'][(int)$opt]['image_url'] = $url;
    else             $store[$idx]['image_url'] = $url;
    docx_store_save($store);
    echo json_encode(['ok'=>true,'url'=>$url], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='docx_reprompt'){  // переписать сюжеты (промты) одного вопроса по текущим правилам
    require_once __DIR__ . '/docx_import.php';
    $id = (string)($_REQUEST['id'] ?? '');

    $store = docx_store_load();
    $idx = null;
    foreach ($store as $i => $e) { if (($e['id'] ?? '') === $id) { $idx = $i; break; } }
    if ($idx === null) { echo json_encode(['ok'=>false,'error'=>'Вопрос не найден'], JSON_UNESCAPED_UNICODE); exit; }

    $labels = array_map(fn($o) => $o['label'], $store[$idx]['options'] ?? []);
    $art = docx_art_prompts($store[$idx]['question'], $labels);
    if (!$art) { echo json_encode(['ok'=>false,'error'=>'Не удалось получить сюжет'], JSON_UNESCAPED_UNICODE); exit; }

    // Промты переписаны — прежние картинки к ним больше не относятся, снимаем ссылки.
    $store[$idx]['image_en'] = $art['event'];
    unset($store[$idx]['image_url']);
    foreach (($store[$idx]['options'] ?? []) as $k => $o) {
        if (!empty($art['options'][$k])) $store[$idx]['options'][$k]['image_en'] = $art['options'][$k];
        unset($store[$idx]['options'][$k]['image_url']);
    }
    docx_store_save($store);
    echo json_encode(['ok'=>true,'event'=>$art['event'],'options'=>array_values($art['options'] ?? [])], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='docx_unrender'){  // убрать картинку у вопроса или варианта (вернуть заглушку)
    require_once __DIR__ . '/docx_import.php';
    $id  = (string)($_REQUEST['id'] ?? '');
    $opt = $_REQUEST['opt'] ?? '';                       // '' = картинка вопроса, иначе индекс варианта

    $store = docx_store_load();
    $idx = null;
    foreach ($store as $i => $e) { if (($e['id'] ?? '') === $id) { $idx = $i; break; } }
    if ($idx === null) { echo json_encode(['ok'=>false,'error'=>'Вопрос не найден'], JSON_UNESCAPED_UNICODE); exit; }

    // Снимаем только ссылку. Сам webp остаётся в кэше: он общий для всех, у кого
    // тот же сюжет и затравка, — удаление файла сломало бы им картинку.
    if ($opt !== '') unset($store[$idx]['options'][(int)$opt]['image_url']);
    else             unset($store[$idx]['image_url']);
    docx_store_save($store);
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='docx_prompt_save'){  // сохранить отредактированный вручную сюжет и список исключений
    require_once __DIR__ . '/docx_import.php';
    $id  = (string)($_REQUEST['id'] ?? '');
    $opt = $_REQUEST['opt'] ?? '';

    $store = docx_store_load();
    $idx = null;
    foreach ($store as $i => $e) { if (($e['id'] ?? '') === $id) { $idx = $i; break; } }
    if ($idx === null) { echo json_encode(['ok'=>false,'error'=>'Вопрос не найден'], JSON_UNESCAPED_UNICODE); exit; }

    $val = trim((string)($_REQUEST['image_en'] ?? ''));
    if ($opt !== '') $store[$idx]['options'][(int)$opt]['image_en'] = $val;
    else             $store[$idx]['image_en'] = $val;

    docx_store_save($store);
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='docx_render'){    // нарисовать/перерисовать картинку вопроса или варианта
    require_once __DIR__ . '/docx_import.php';
    $id  = (string)($_REQUEST['id'] ?? '');
    $opt = $_REQUEST['opt'] ?? '';                       // '' = картинка вопроса, иначе индекс варианта
    $force = !empty($_REQUEST['force']);                 // перерисовать, даже если уже есть

    $store = docx_store_load();
    $idx = null;
    foreach ($store as $i => $e) { if (($e['id'] ?? '') === $id) { $idx = $i; break; } }
    if ($idx === null) { echo json_encode(['ok'=>false,'error'=>'Вопрос не найден'], JSON_UNESCAPED_UNICODE); exit; }

    $isOpt   = ($opt !== '');
    $subject = $isOpt ? ($store[$idx]['options'][(int)$opt]['image_en'] ?? '') : ($store[$idx]['image_en'] ?? '');
    if ($subject === '') { echo json_encode(['ok'=>false,'error'=>'Нет сюжета для картинки'], JSON_UNESCAPED_UNICODE); exit; }

    $seedSrc = $isOpt ? ($store[$idx]['options'][(int)$opt]['label'] ?? '') : $id;
    $r = docx_render_image($subject, $seedSrc, $isOpt ? 128 : 512, $force);
    if (!$r['ok']) { echo json_encode(['ok'=>false,'error'=>$r['error']], JSON_UNESCAPED_UNICODE); exit; }

    // Запоминаем адрес готового файла, чтобы страница показала картинку сразу.
    if ($isOpt) $store[$idx]['options'][(int)$opt]['image_url'] = $r['url'];
    else        $store[$idx]['image_url'] = $r['url'];
    docx_store_save($store);
    echo json_encode(['ok'=>true,'url'=>$r['url']], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='presets'){ echo json_encode(['ok'=>true,'presets'=>$PRESETS], JSON_UNESCAPED_UNICODE); exit; }
if ($action==='meta'){ echo json_encode(['ok'=>true,'categories'=>$CATS,'timeframes'=>$TF_HINTS], JSON_UNESCAPED_UNICODE); exit; }
if ($action==='pools'){   // какие активы есть в каждой код-категории (для показа в лайв-каналах)
    $out = [];
    foreach ($POOLS as $cat=>$list){ $out[$cat] = array_map(fn($x)=>asset_display($x['t']), $list); }
    echo json_encode(['ok'=>true,'pools'=>$out], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='img_prewarm'){   // пре-генерация картинок событий в кэш (ручной триггер/тест)
    $n = img_prewarm(max(1, min(20, (int)($_REQUEST['limit'] ?? 6))));
    echo json_encode(['ok'=>true,'cached'=>$n], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='relogo'){   // пересчитать логотипы существующих событий по текущим правилам (убрать залипшие лого стран/валют)
    $changed = 0;
    foreach ([PS_DATA.'/events.json', PS_DATA.'/pending.json'] as $f){
        $arr = jload($f); if (!is_array($arr)) continue;
        foreach ($arr as &$e){
            $lg = !empty($e['question']) ? company_logo_for($e['question']) : null;
            if ($lg){ if (($e['logo'] ?? null) !== $lg){ $e['logo'] = $lg; $changed++; } }
            elseif (isset($e['logo'])){ unset($e['logo']); $changed++; }   // логотип больше не подходит -> уберём, будет сцена
        } unset($e);
        jsave($f, $arr);
    }
    echo json_encode(['ok'=>true,'changed'=>$changed], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='pending_list'){  // «будет событием» — прогнозы, ждущие генерации картинки
    $p = jload(PS_DATA.'/pending.json'); if (!is_array($p)) $p = [];
    $cfg = jload(PS_DATA.'/config.json'); if (!is_array($cfg)) $cfg = [];
    $provider = $cfg['img_provider'] ?? 'pollinations';
    $tpl = $cfg['img_prompt'] ?? '{q}';
    $dir = PS_DATA.'/genimg';
    $webPath = function($subject, $seedSrc, $w) use ($provider,$tpl,$dir){
        $subject = trim((string)$subject); if ($subject==='') return null;
        $prompt = strpos($tpl,'{q}')!==false ? str_replace('{q}',$subject,$tpl) : ($subject.', '.$tpl);
        $seed = img_hashn($seedSrc) % 100000;
        $key = substr(md5($prompt.'|'.$w.'|'.$seed),0,20);   // БЕЗ провайдера
        $f = $dir.'/w_'.$key.'.webp';
        return (is_file($f) && filesize($f)>0) ? 'data/genimg/w_'.$key.'.webp' : null;
    };
    foreach ($p as &$e){
        $total=0; $ready=0;
        if (!empty($e['image_en'])){ $total++; if ($webPath($e['image_en'],$e['id']??'',512)) $ready++; }
        if (($e['type']??'')==='open' && !empty($e['options'])){
            foreach ($e['options'] as &$o){
                if (!empty($o['image_en'])){
                    $total++;
                    $wp = $webPath($o['image_en'], $o['label']??'', 128);   // готовый webp варианта или null
                    if ($wp){ $ready++; $o['thumb'] = $wp; } else { $o['thumb'] = ''; }
                }
            }
            unset($o);
        }
        $e['img_ready']=$ready; $e['img_total']=$total;
        // готовая картинка-заголовок (для превью слева): лого -> сгенерированный webp -> нет
        $e['thumb'] = !empty($e['logo']) ? $e['logo'] : (!empty($e['image_en']) ? ($webPath($e['image_en'],$e['id']??'',512) ?? '') : '');
    } unset($e);
    echo json_encode(['ok'=>true,'pending'=>$p,'count'=>count($p)], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='promote_pending'){  // опубликовать готовые (ручной триггер/тест)
    $n = promote_pending(max(1, min(20, (int)($_REQUEST['limit'] ?? 5))));
    echo json_encode(['ok'=>true,'promoted'=>$n], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='logos_list'){
    $m = jload(PS_DATA . '/logos/_manifest.json'); if (!is_array($m)) $m = [];
    $out = []; foreach ($m as $name=>$file){ $out[] = ['name'=>$name, 'url'=>'data/logos/'.$file]; }
    echo json_encode(['ok'=>true,'logos'=>$out], JSON_UNESCAPED_UNICODE); exit;
}
/* сгенерировать N вариантов логотипа для имени (в data/logos/_cand/), вернуть их URL */
if ($action==='logo_variants'){
    require_once __DIR__ . '/imglib.php';
    $name = trim($_REQUEST['name'] ?? '');
    if ($name===''){ echo json_encode(['ok'=>false,'error'=>'нет имени']); exit; }
    $count = max(1, min(6, (int)($_REQUEST['count'] ?? 5)));
    $dir = PS_DATA . '/logos/_cand'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
    foreach (glob($dir.'/*') as $f) @unlink($f);   // чистим прошлые кандидаты
    $cfg = img_cfg();
    // отдельный редактируемый промт логотипов ({name} = название); настраивается во вкладке «Логотипы»
    $cfgAll = jload(PS_DATA . '/config.json'); if (!is_array($cfgAll)) $cfgAll = [];
    $tpl = trim((string)($cfgAll['logo_prompt'] ?? ''));
    if ($tpl === '') $tpl = '{name} logo, flat vector icon, centered, fills 90 percent of the frame, fully inside edges, solid plain background, high contrast, no text';
    $prompt = (strpos($tpl, '{name}') !== false) ? str_replace('{name}', $name, $tpl) : ($name . ', ' . $tpl);
    $urls = [];
    for ($i=0; $i<$count; $i++){
        $err=''; $used='';
        $raw = img_generate_chain($prompt, 256, ($i+1)*17 + random_int(1,99999), $cfg, $err, $used);
        if ($raw === false || !$raw) continue;
        $webp = img_to_webp($raw); $ext = $webp!==false?'webp':'jpg'; $bytes = $webp!==false?$webp:$raw;
        $file = 'cand_' . substr(md5($name.$i.microtime(true).random_int(0,9999)),0,14) . '.' . $ext;
        file_put_contents($dir.'/'.$file, $bytes);
        $urls[] = 'data/logos/_cand/'.$file;
    }
    echo json_encode(['ok'=>!empty($urls),'name'=>$name,'variants'=>$urls,'error'=>(empty($urls)?'генерация не удалась (проверь провайдеров картинок)':'')], JSON_UNESCAPED_UNICODE); exit;
}
/* применить выбранный вариант как логотип имени: копируем в logos/, пишем манифест + ключевые слова */
if ($action==='logo_apply'){
    $name = trim($_REQUEST['name'] ?? '');
    $cand = basename(trim($_REQUEST['file'] ?? ''));
    if ($name==='' || $cand===''){ echo json_encode(['ok'=>false,'error'=>'нет имени/файла']); exit; }
    $src = PS_DATA . '/logos/_cand/' . $cand;
    if (!is_file($src)){ echo json_encode(['ok'=>false,'error'=>'вариант не найден']); exit; }
    $ext = pathinfo($cand, PATHINFO_EXTENSION) ?: 'webp';
    $file = 'gen_' . substr(md5($name),0,14) . '.' . $ext;
    $man = jload(PS_DATA.'/logos/_manifest.json'); if (!is_array($man)) $man = [];
    if (isset($man[$name]) && $man[$name]!==$file && is_file(PS_DATA.'/logos/'.$man[$name])) @unlink(PS_DATA.'/logos/'.$man[$name]);
    @copy($src, PS_DATA.'/logos/'.$file);
    $man[$name] = $file; jsave(PS_DATA.'/logos/_manifest.json', $man);
    // ключевые слова для авто-подхвата (по умолчанию — само имя; можно передать свои через &keywords=)
    $kwIn = trim($_REQUEST['keywords'] ?? $name);
    $stems = array_values(array_filter(array_map(fn($s)=>mb_strtolower(trim($s),'UTF-8'), preg_split('/[,\s]+/u', $kwIn)), fn($s)=>mb_strlen($s)>=3));
    if (!$stems) $stems = [mb_strtolower($name,'UTF-8')];
    $kw = jload(PS_DATA.'/logos/_keywords.json'); if (!is_array($kw)) $kw = [];
    $kw[$name] = $stems; jsave(PS_DATA.'/logos/_keywords.json', $kw);
    foreach (glob(PS_DATA.'/logos/_cand/*') as $f) @unlink($f);   // чистим кандидатов
    echo json_encode(['ok'=>true,'name'=>$name,'url'=>'data/logos/'.$file], JSON_UNESCAPED_UNICODE); exit;
}
/* применить выбранный вариант как ЗАГЛУШКУ (одна общая картинка для «пока грузится») */
if ($action==='placeholder_apply'){
    $cand = basename(trim($_REQUEST['file'] ?? ''));
    if ($cand===''){ echo json_encode(['ok'=>false,'error'=>'нет файла']); exit; }
    $src = PS_DATA . '/logos/_cand/' . $cand;
    if (!is_file($src)){ echo json_encode(['ok'=>false,'error'=>'вариант не найден']); exit; }
    @copy($src, PS_DATA . '/logos/_placeholder.webp');
    $cfg = jload(PS_DATA.'/config.json'); if (!is_array($cfg)) $cfg = [];
    $cfg['placeholder_img'] = 'data/logos/_placeholder.webp';
    jsave(PS_DATA.'/config.json', $cfg);
    foreach (glob(PS_DATA.'/logos/_cand/*') as $f) @unlink($f);
    echo json_encode(['ok'=>true,'url'=>'data/logos/_placeholder.webp'], JSON_UNESCAPED_UNICODE); exit;
}
/* удалить логотип: файл + запись в манифесте + ключевые слова */
if ($action==='logo_del'){
    $name = trim($_REQUEST['name'] ?? '');
    $man = jload(PS_DATA.'/logos/_manifest.json'); if (!is_array($man)) $man = [];
    if (isset($man[$name])){ if (is_file(PS_DATA.'/logos/'.$man[$name])) @unlink(PS_DATA.'/logos/'.$man[$name]); unset($man[$name]); jsave(PS_DATA.'/logos/_manifest.json', $man); }
    $kw = jload(PS_DATA.'/logos/_keywords.json'); if (is_array($kw) && isset($kw[$name])){ unset($kw[$name]); jsave(PS_DATA.'/logos/_keywords.json', $kw); }
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE); exit;
}
/* подсказать слова из ответов событий, для которых стоит завести логотип (нет матча) */
if ($action==='logo_suggest'){
    $events = jload($DATA); if (!is_array($events)) $events = [];
    // имена, которые уже есть в манифесте (по первому слову) — их не предлагаем повторно
    $man = jload(PS_DATA.'/logos/_manifest.json'); if (!is_array($man)) $man = [];
    $haveNames = [];
    foreach (array_keys($man) as $nm){ $haveNames[mb_strtolower((string)(preg_split('/[\s(]+/u', trim((string)$nm))[0] ?? ''),'UTF-8')] = true; }
    $counts = [];
    foreach ($events as $e){
        if (($e['type'] ?? '')!=='open') continue;
        foreach (($e['options'] ?? []) as $o){
            $label = trim((string)($o['label'] ?? ''));
            if ($label==='' || is_numeric_answer($label)) continue;
            if (count(preg_split('/\s+/u', $label)) > 3) continue;   // 1–3 слова
            if (company_logo_for($label)) continue;                   // уже есть матч/лого (бренд/концепт)
            $first = mb_strtolower((string)(preg_split('/[\s(]+/u', $label)[0] ?? ''),'UTF-8');
            if (isset($haveNames[$first])) continue;                  // уже есть в манифесте по имени — не дублируем
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
    }
    arsort($counts);
    $out = []; foreach ($counts as $label=>$n){ $out[] = ['label'=>$label,'count'=>$n]; if (count($out)>=30) break; }
    echo json_encode(['ok'=>true,'suggestions'=>$out], JSON_UNESCAPED_UNICODE); exit;
}

/* ---- генерация картинок (Pollinations, бесплатно, без ключей) + счётчик ---- */
function imgusage_file(){ return PS_DATA . '/img_usage.json'; }
function imgusage_bump($ok){
    $all = jload(imgusage_file()); if (!is_array($all)) $all = [];
    $d = date('Y-m-d'); $x = $all[$d] ?? ['ok'=>0,'fail'=>0];
    if ($ok) $x['ok']++; else $x['fail']++;
    $all[$d] = $x;
    if (count($all) > 60){ ksort($all); $all = array_slice($all, -60, null, true); }
    jsave(imgusage_file(), $all);
}
function imgusage_today(){ $all = jload(imgusage_file()); $d = date('Y-m-d'); return (is_array($all) && isset($all[$d])) ? $all[$d] : ['ok'=>0,'fail'=>0]; }
if ($action==='img_usage_get'){
    echo json_encode(['ok'=>true,'day'=>date('Y-m-d'),'usage'=>imgusage_today()]); exit;
}
if ($action==='img_gen'){
    require_once __DIR__ . '/imglib.php';
    $p = trim($_REQUEST['prompt'] ?? '');
    if ($p===''){ echo json_encode(['ok'=>false,'error'=>'пустой промпт']); exit; }
    $dir = PS_DATA . '/genimg'; if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $cfg = img_cfg();
    // провайдер явно в запросе -> тестируем только его; иначе -> активный + авто-фолбэк (как на ленте)
    $explicit = isset($_REQUEST['provider']) && trim($_REQUEST['provider']) !== '';
    $provider = strtolower(trim($_REQUEST['provider'] ?? $cfg['img_provider'] ?? 'pollinations'));
    if (!in_array($provider, ['pollinations','cloudflare','together','segmind'], true)) $provider = 'pollinations';
    $seed = random_int(0, 99999);
    $t0 = microtime(true);
    $err = '';
    $used = $provider;
    $raw = $explicit
        ? img_generate_raw($p, 512, $seed, $provider, $cfg, $err)
        : img_generate_chain($p, 512, $seed, $cfg, $err, $used);
    $provider = $used;
    if ($raw === false || !$raw){ imgusage_bump(false); echo json_encode(['ok'=>false,'provider'=>$provider,'error'=>$err]); exit; }
    $webp = img_to_webp($raw);
    $ext = $webp !== false ? 'webp' : 'jpg';
    $bytes = $webp !== false ? $webp : $raw;
    $file = 'g_' . substr(md5($p . microtime(true) . $seed), 0, 16) . '.' . $ext;
    file_put_contents($dir.'/'.$file, $bytes);
    imgusage_bump(true);
    $ms = (int)round((microtime(true)-$t0)*1000);
    echo json_encode(['ok'=>true,'provider'=>$provider,'url'=>'data/genimg/'.$file,'bytes'=>strlen($bytes),'ms'=>$ms,'format'=>$ext]); exit;
}

/* ---- детальная пер-активная генерация (каждый актив × интервалы) ---- */
if ($action==='assets_list'){   // все активы по категориям + доступные интервалы
    $out = [];
    foreach ($POOLS as $cat=>$items){
        $out[] = ['cat'=>$cat, 'label'=>$CAT_LABEL[$cat] ?? $cat, 'assets'=>array_map(fn($x)=>$x['t'], $items)];
    }
    echo json_encode(['ok'=>true,'pools'=>$out,'intervals'=>['5m','15m','30m','1h','4h','1d','1w']], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='asset_gen_get'){
    $d = assetgen_load();
    echo json_encode(['ok'=>true,'assets'=>($d['assets'] ?? (object)[])], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='asset_gen_set'){   // настроить один актив: ivs (список интервалов), every(сек), count
    $t = trim($_REQUEST['ticker'] ?? '');
    $cat = preg_replace('/[^a-z0-9_]/','', strtolower($_REQUEST['cat'] ?? ''));
    if ($t==='' || $cat===''){ echo json_encode(['ok'=>false,'error'=>'no ticker/cat']); exit; }
    $ivs = array_values(array_filter(array_map('trim', explode(',', $_REQUEST['ivs'] ?? ''))));
    $every = max(60, (int)($_REQUEST['every'] ?? 3600));
    $count = max(1, min(10, (int)($_REQUEST['count'] ?? 1)));
    $d = assetgen_load(); if (!isset($d['assets']) || !is_array($d['assets'])) $d['assets'] = [];
    if (!$ivs) unset($d['assets'][$t]);
    else $d['assets'][$t] = ['cat'=>$cat, 'ivs'=>$ivs, 'every'=>$every, 'count'=>$count];
    assetgen_save($d);
    echo json_encode(['ok'=>true]); exit;
}
if ($action==='asset_gen_bulk'){   // включить/выключить все активы категории на наборе интервалов
    $cat = preg_replace('/[^a-z0-9_]/','', strtolower($_REQUEST['cat'] ?? ''));
    $on  = ($_REQUEST['on'] ?? '0')==='1';
    $ivs = array_values(array_filter(array_map('trim', explode(',', $_REQUEST['ivs'] ?? ''))));
    $every = max(60, (int)($_REQUEST['every'] ?? 3600));
    $count = max(1, min(10, (int)($_REQUEST['count'] ?? 1)));
    if (!isset($POOLS[$cat])){ echo json_encode(['ok'=>false]); exit; }
    $d = assetgen_load(); if (!isset($d['assets']) || !is_array($d['assets'])) $d['assets'] = [];
    foreach ($POOLS[$cat] as $x){
        $t = $x['t'];
        if ($on && $ivs) $d['assets'][$t] = ['cat'=>$cat, 'ivs'=>$ivs, 'every'=>$every, 'count'=>$count];
        else unset($d['assets'][$t]);
    }
    assetgen_save($d);
    echo json_encode(['ok'=>true]); exit;
}
if ($action==='clear'){ save_events($DATA, []); echo json_encode(['ok'=>true,'total'=>0]); exit; }
if ($action==='list'){ echo json_encode(['ok'=>true,'events'=>load_events($DATA)], JSON_UNESCAPED_UNICODE); exit; }

if ($action==='generate'){
    $preset = $_REQUEST['preset'] ?? '';
    if ($preset && isset($PRESETS[$preset])){
        $c = $PRESETS[$preset];
        $category=$c['category']; $type=$c['type']; $tf=$c['timeframe']; $count=$c['count'];
    } else {
        $category = preg_replace('/[^a-z0-9_]/','', strtolower($_REQUEST['category'] ?? 'crypto'));
        $type     = ($_REQUEST['type'] ?? 'closed')==='open' ? 'open' : 'closed';
        $tf       = $_REQUEST['timeframe'] ?? '1h';
        $count    = max(1, min(40, (int)($_REQUEST['count'] ?? 5)));
    }
    $events = load_events($DATA); $added = [];
    for ($i=0;$i<$count;$i++){ $added[] = gen_one($category,$type,$tf); }
    $events = array_merge($added, $events);
    if (count($events) > $GLOBALS['MAX_EVENTS']) $events = array_slice($events, 0, $GLOBALS['MAX_EVENTS']);
    save_events($DATA, $events);
    echo json_encode(['ok'=>true,'added'=>count($added),'total'=>count($events),
                      'category'=>$category,'type'=>$type,'timeframe'=>$tf], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- auto-generation schedules ---------- */
if ($action==='sched_list'){ echo json_encode(['ok'=>true,'schedules'=>jload($SCHED)], JSON_UNESCAPED_UNICODE); exit; }

if ($action==='sched_add'){
    $sch = jload($SCHED);
    $sch[] = [
        'id'=>rid(),
        'category'=>preg_replace('/[^a-z0-9_]/','', strtolower($_REQUEST['category'] ?? 'crypto')),
        'type'=>($_REQUEST['type'] ?? 'closed')==='open' ? 'open' : 'closed',
        'timeframe'=>$_REQUEST['timeframe'] ?? '5m',
        'interval'=>max(5, parse_timeframe($_REQUEST['interval'] ?? '5m')),   // seconds between generations
        'count'=>max(1, min(10, (int)($_REQUEST['count'] ?? 1))),
        'active'=>true, 'last_run'=>0, 'created_at'=>date('c'),
    ];
    jsave($SCHED, $sch);
    echo json_encode(['ok'=>true,'total'=>count($sch)]); exit;
}
if ($action==='sched_toggle'){
    $id=$_REQUEST['id'] ?? ''; $sch=jload($SCHED);
    foreach ($sch as &$s){ if ($s['id']===$id) $s['active']=empty($s['active']); } unset($s);
    jsave($SCHED,$sch); echo json_encode(['ok'=>true]); exit;
}
if ($action==='sched_update'){   // изменить частоту выдачи (interval, сек) и/или кол-во
    $id=$_REQUEST['id'] ?? ''; $sch=jload($SCHED);
    foreach ($sch as &$s){ if ($s['id']===$id){
        if (isset($_REQUEST['interval'])) $s['interval'] = max(30, (int)$_REQUEST['interval']);
        if (isset($_REQUEST['count']))    $s['count']    = max(1, min(20, (int)$_REQUEST['count']));
        if (isset($_REQUEST['active']))   $s['active']   = ($_REQUEST['active']==='1');
    }} unset($s);
    jsave($SCHED,$sch); echo json_encode(['ok'=>true]); exit;
}
if ($action==='sched_del'){
    $id=$_REQUEST['id'] ?? '';
    jsave($SCHED, array_values(array_filter(jload($SCHED), fn($s)=>$s['id']!==$id)));
    echo json_encode(['ok'=>true]); exit;
}
/* ---- РЫНКИ (код-генерация): сид расписаний по интервалам + массовое вкл/выкл ---- */
if ($action==='markets_seed'){
    $MARKET = ['crypto','stocks','currency','indexes','metals','commodities'];
    $IVS    = ['5m','15m','30m','1h','4h','1d','1w','1M'];   // «Лайв» интервалы
    $BATTLE = ['battles_global','battles_ru_world']; $BIVS = ['1w','1M','1y'];
    $sch = jload($SCHED);
    $old = [];   // сохраняем вкл/частоту/кол-во существующих каналов, чтобы пересид не сбрасывал настройки
    foreach ($sch as $s){ if (!empty($s['market'])) $old[$s['category'].'|'.$s['timeframe']] = $s; }
    $sch = array_values(array_filter($sch, fn($s)=>empty($s['market'])));
    $mk = function($cat,$iv) use ($old) {
        $o = $old[$cat.'|'.$iv] ?? null;
        return ['id'=>rid(),'category'=>$cat,'type'=>'open','timeframe'=>$iv,   // open = «какая X будет выше»
                'interval'=> $o ? max(30,(int)$o['interval']) : max(30, parse_timeframe($iv)),
                'count'=>    $o ? max(1,(int)$o['count'])      : 1,
                'active'=>   $o ? !empty($o['active'])         : false,
                'last_run'=> $o ? (int)($o['last_run'] ?? 0)   : 0,
                'market'=>true,'created_at'=>date('c')];
    };
    foreach ($MARKET as $cat){ foreach ($IVS  as $iv){ $sch[] = $mk($cat,$iv); } }
    foreach ($BATTLE as $cat){ foreach ($BIVS as $iv){ $sch[] = $mk($cat,$iv); } }
    jsave($SCHED, $sch);
    echo json_encode(['ok'=>true,'total'=>count($sch),'market'=>count($MARKET)*count($IVS)+count($BATTLE)*count($BIVS)]); exit;
}
if ($action==='markets_active'){   // включить/выключить ВСЕ рыночные расписания разом
    $on = ($_REQUEST['on'] ?? '0')==='1';
    $sch = jload($SCHED);
    foreach ($sch as &$s){ if (!empty($s['market'])) $s['active'] = $on; } unset($s);
    jsave($SCHED, $sch);
    $n = count(array_filter($sch, fn($s)=>!empty($s['market'])));
    echo json_encode(['ok'=>true,'active'=>$on,'count'=>$n]); exit;
}

/* ---- авто-разрешение: у событий с реальной котировкой сверяем итог по факту ---- */
function resolve_due(){
    global $DATA;
    $events = jload($DATA); $now = time(); $changed = false;
    foreach ($events as &$e){
        if (!empty($e['result'])) continue;                       // уже разрешено
        if (empty($e['symbol']) || !isset($e['ref_price'])) continue;  // не котировочное
        $rt = strtotime($e['resolves_at'] ?? ''); if (!$rt || $rt > $now) continue;  // срок ещё не вышел
        $price = asset_price($e['category'] ?? 'crypto', $e['symbol']);
        if ($price === null) continue;                            // нет цены сейчас — попробуем позже
        $win = (($e['metric'] ?? 'above')==='above') ? ($price > $e['ref_price']) : ($price < $e['ref_price']);
        $e['result'] = $win ? 'ДА' : 'НЕТ';
        $e['final_price'] = $price;
        $e['resolved_at'] = date('c');
        $changed = true;
    }
    unset($e);
    if ($changed) jsave($DATA, $events);
    return $changed;
}
if ($action==='resolve'){ $c = resolve_due(); echo json_encode(['ok'=>true,'changed'=>$c]); exit; }

/* one pass over schedules — generate whatever is due. Called by the worker every ~10s (or by cron). */
if ($action==='tick'){
    resolve_due();   // закрытие просроченных событий работает ВСЕГДА, независимо от кнопок
    // НЕ-ИИ генерация (лайв-каналы/рынки) управляется ТОЛЬКО своим переключателем live_active.
    // (Кнопка «Старт» в «Настройки генерации» относится только к ИИ-генерации из новостей.)
    $cfgAll = jload(PS_DATA . '/config.json'); if (!is_array($cfgAll)) $cfgAll = [];
    if (!empty($cfgAll['paused'])){ echo json_encode(['ok'=>true,'skipped'=>'paused','generated'=>0]); exit; }   // экстренный стоп
    $liveOn = !array_key_exists('live_active', $cfgAll) || !empty($cfgAll['live_active']); // по умолчанию вкл
    if (!$liveOn){ echo json_encode(['ok'=>true,'skipped'=>'live off','generated'=>0]); exit; }
    $sch=jload($SCHED); $events=jload($DATA); $now=time(); $gen=0; $changed=false;
    foreach ($sch as &$s){
        if (empty($s['active'])) continue;
        $iv=max(5,(int)$s['interval']);
        if ($now - (int)$s['last_run'] >= $iv){
            $cnt=max(1,(int)$s['count']);
            for ($i=0;$i<$cnt;$i++){ $events=array_merge([gen_one($s['category'],$s['type'],$s['timeframe'])], $events); $gen++; }
            $s['last_run']=$now; $changed=true;
        }
    }
    unset($s);
    if ($gen){ if (count($events) > $MAX_EVENTS) $events=array_slice($events,0,$MAX_EVENTS); jsave($DATA,$events); }
    if ($changed) jsave($SCHED,$sch);

    // --- детальная пер-активная генерация (каждый актив × интервалы) ---
    $d = assetgen_load(); $runs = $d['runs'] ?? []; $ag = 0; $agChanged = false;
    $ev2 = jload($DATA);
    foreach (($d['assets'] ?? []) as $ticker=>$cfg){
        $every = max(60,(int)($cfg['every'] ?? 3600)); $cnt = max(1,(int)($cfg['count'] ?? 1)); $cat = $cfg['cat'] ?? '';
        foreach (($cfg['ivs'] ?? []) as $iv){
            $key = $ticker.'|'.$iv;
            if ($now - (int)($runs[$key] ?? 0) >= $every){
                for ($i=0;$i<$cnt;$i++){ $ev2 = array_merge([gen_asset_specific($cat,$ticker,$iv)], $ev2); $ag++; }
                $runs[$key] = $now; $agChanged = true;
            }
        }
    }
    if ($ag){ if (count($ev2) > $MAX_EVENTS) $ev2 = array_slice($ev2,0,$MAX_EVENTS); jsave($DATA,$ev2); }
    if ($agChanged){ $d['runs'] = $runs; assetgen_save($d); }

    echo json_encode(['ok'=>true,'generated'=>$gen + $ag]); exit;
}

/* ---------- custom categories management ---------- */
if ($action==='cat_list'){ echo json_encode(['ok'=>true,'categories'=>jload($CATSFILE)], JSON_UNESCAPED_UNICODE); exit; }

if ($action==='cat_add'){
    $label = trim($_REQUEST['label'] ?? '');
    if ($label===''){ echo json_encode(['ok'=>false,'error'=>'no label']); exit; }
    $custom = jload($CATSFILE);
    $opts = array_values(array_filter(array_map('trim', explode(',', $_REQUEST['options'] ?? ''))));
    $custom[] = [
        'code'=>'c_' . bin2hex(random_bytes(4)),
        'label'=>$label,
        'group'=>(trim($_REQUEST['group'] ?? '') ?: 'Свои'),
        'question'=>trim($_REQUEST['question'] ?? ''),
        'options'=>$opts,
    ];
    jsave($CATSFILE, $custom);
    echo json_encode(['ok'=>true,'total'=>count($custom)]); exit;
}
if ($action==='cat_del'){
    $code = $_REQUEST['code'] ?? '';
    jsave($CATSFILE, array_values(array_filter(jload($CATSFILE), fn($c)=>($c['code'] ?? '')!==$code)));
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'unknown action']);
