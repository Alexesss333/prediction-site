<?php
/**
 * News pipeline: fetch RSS/Google-News -> read -> Gemini -> prediction event -> feed.
 *
 * Actions:
 *   ?action=news_fetch         pull all sources, store new items (status "new")
 *   ?action=news_list          list stored news
 *   ?action=news_status&id=&status=   set status (new|used|rejected)
 *   ?action=news_del&id=
 *   ?action=news_to_event&id=  Gemini: turn one news item into an event -> feed
 *   ?action=news_tick          auto pass (called by the worker): fetch + convert due
 *   ?action=config_get / config_save   pipeline settings (sources, gemini_key, auto)
 */
define('GEN_INCLUDE', true);
require_once __DIR__ . '/generate.php';   // jload/jsave, img_badge, ev_base, parse_timeframe, gen helpers, $DATA, $CAT_LABEL
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$NEWS   = PS_DATA . '/news.json';
$CONFIG = PS_DATA . '/config.json';

function cfg(){
    global $CONFIG;
    $c = jload($CONFIG); if (!is_array($c)) $c = [];
    $c += [
        'gemini_keys' => [],    // несколько ключей: если первый отвалился — берётся следующий
        'gemini_models' => ['gemini-flash-lite-latest','gemini-flash-latest','gemini-pro-latest'],
        'sources' => ['война России Украина','Путин заявление','Кремль Госдума','санкции против России','курс рубля','ключевая ставка ЦБ РФ','цена нефти газа Россия','экономика России прогноз'],
        'auto' => ['active'=>false, 'interval'=>120, 'per_run'=>3, 'auto_publish'=>true, 'last_run'=>0],
        'model_status' => [],   // model => ['state'=>ok|limit|error, 'msg'=>текст, 'ts'=>время]
        'active_model' => '',   // модель, которая сработала последней (подсвечивается зелёным)
        'key_status'   => [],   // tail(4) => ['state'=>ok|limit|error, 'msg'=>текст, 'ts'=>время]
        'active_key'   => '',   // хвост(4 символа) ключа, который сработал последним
        'last_error'   => '',   // последняя ошибка человеческим языком
        'rank_prompt'  => '',   // критерии важности (пусто => берётся default_rank_prompt())
        'gen_prompt'   => '',   // доп. правила генерации события (длина названия, число вариантов и т.п.)
        'rank_keep'    => 10,   // сколько важных новостей хранить/показывать в отборе
        'rpd_limit'    => 500,  // дневной лимит запросов (для наглядного «осталось»); правится в админке
        'cat_timeframe'=> [],   // правило срока для каждой категории (код => текст-правило); пусто => default
        'news_keep'    => 300,  // сколько новостей хранить в пуле (сбор бесплатный); правится в админке
        'feed_per_cat' => 6,    // сколько прогнозов показывать на категорию в ленте (вкладки)
        'img_prompt'   => '{q}, realistic press wire photo like Reuters or Associated Press, everyday news photojournalism, real-world scene, bright even natural daylight, well-lit, neutral true-to-life colors, normal exposure, sharp clear focus, plain ordinary look like a news website thumbnail, main subject large and centered, close-up framing, subject fills the frame, simple clean uncluttered background, not dark, no moody or low-key lighting, no heavy shadows on the subject, no vignette, no teal-and-orange color grading, no cinematic style, no dramatic lighting, no film look, any charts or screens look like a plain real monitor or printed paper, no cyberpunk, no neon, no glowing holograms, no futuristic sci-fi, no digital art, no 3d render, no illustration',  // шаблон промпта картинок ({q}=англ. сцена)
        'logo_prompt'  => '{name} logo. If {name} is a real company or brand, render its most recent official logo and emblem, accurate and recognizable. If it is a generic thing (grain, oil, metal, wheat), draw a clear simple illustration of it. The logo fills about 90 percent of the frame, fully inside the edges, centered, not cropped. Flat, clean, solid plain background, high contrast, no extra text.',  // шаблон промпта ЛОГОТИПОВ ({name}=название)
        'placeholder_img' => '',  // заглушка (показывается на карточке, пока не готова настоящая картинка)
        'market_options'=> 5,   // общее число вариантов в код-прогнозах рынков (цена/гонки)
        'market_opts_iv'=> [],   // число вариантов ОТДЕЛЬНО по интервалам {"5m":3,"1d":5,...}; нет ключа => общее
        'gen_cat_rr'   => 0,    // указатель ротации категорий при генерации (по кругу)
        'live_active'  => true, // Старт/Стоп блока «Лайв-каналы» (учитывается master-кнопкой)
        'paused'       => false,// ЭКСТРЕННЫЙ СТОП: true = вся генерация выключена везде (новости, рынки, картинки)
        // --- генерация картинок: провайдер + ключи ---
        'img_provider' => 'pollinations',  // активный сервис: pollinations|cloudflare|together|segmind
        'cf_keys'      => [],   // Cloudflare: список [{account, token}] — несколько с фолбэком
        'cf_model'     => '@cf/black-forest-labs/flux-1-schnell',
        'together_keys'=> [],   // Together: список ключей [str,...]
        'together_model'=> 'black-forest-labs/FLUX.1-schnell-Free',
        'segmind_keys' => [],   // Segmind: список ключей [str,...]
        'segmind_model'=> 'fast-flux-schnell',   // endpoint-слаг Segmind
        // старые одиночные поля (для миграции)
        'cf_account'   => '', 'cf_token' => '', 'together_key' => '', 'segmind_key' => '',
    ];
    // обратная совместимость: старые одиночные поля -> списки
    if (empty($c['gemini_models']) && !empty($c['gemini_model'])) $c['gemini_models'] = [$c['gemini_model']];
    if (empty($c['gemini_keys'])   && !empty($c['gemini_key']))   $c['gemini_keys']   = [$c['gemini_key']];
    // ключи картинок: одиночные поля -> массивы
    if (empty($c['cf_keys'])       && !empty($c['cf_token']))     $c['cf_keys']       = [['account'=>$c['cf_account']??'', 'token'=>$c['cf_token']]];
    if (empty($c['together_keys']) && !empty($c['together_key'])) $c['together_keys'] = [$c['together_key']];
    if (empty($c['segmind_keys'])  && !empty($c['segmind_key']))  $c['segmind_keys']  = [$c['segmind_key']];
    if (trim((string)$c['rank_prompt']) === '') $c['rank_prompt'] = default_rank_prompt();
    return $c;
}
/* снапшот последнего ранжирования — для компактного дашборда в админке */
function rank_file(){ return PS_DATA . '/rank.json'; }
function save_rank($s){ jsave(rank_file(), $s); }
function get_rank(){ $r = jload(rank_file()); return is_array($r) ? $r : []; }

/* ---- учёт расхода Gemini: реальные запросы и токены (из usageMetadata ответа) ---- */
function usage_file(){ return PS_DATA . '/usage.json'; }
/* день по тихоокеанскому времени — как сбрасывается дневной лимит (RPD) у Google */
function usage_day(){
    try { $d = new DateTime('now', new DateTimeZone('America/Los_Angeles')); return $d->format('Y-m-d'); }
    catch (Exception $e) { return date('Y-m-d'); }
}
function record_usage($kind, $usage){
    $all = jload(usage_file()); if (!is_array($all)) $all = [];
    $day = usage_day();
    $d = $all[$day] ?? ['requests'=>0,'rank'=>0,'event'=>0,'in'=>0,'out'=>0,'total'=>0];
    $d['requests']++;
    if ($kind === 'rank') $d['rank']++; else $d['event']++;
    $d['in']    += (int)($usage['in']    ?? 0);
    $d['out']   += (int)($usage['out']   ?? 0);
    $d['total'] += (int)($usage['total'] ?? 0);
    $all[$day] = $d;
    if (count($all) > 90){ ksort($all); $all = array_slice($all, -90, null, true); }
    jsave(usage_file(), $all);
}
function usage_get(){
    $all = jload(usage_file()); if (!is_array($all)) $all = [];
    $day = usage_day();
    $blank = ['requests'=>0,'rank'=>0,'event'=>0,'in'=>0,'out'=>0,'total'=>0];
    $c = cfg();
    return ['day'=>$day, 'today'=>($all[$day] ?? $blank), 'limit'=>(int)($c['rpd_limit'] ?? 500)];
}
function key_tail($k){ $k = trim($k); return strlen($k) >= 4 ? substr($k, -4) : $k; }
function cfg_save($c){ global $CONFIG; jsave($CONFIG, $c); }
/* авто-очистка устаревших ошибок «нет связи» (сеть моргнула).
   $maxAge=0 — убрать все (был успех, сеть точно жива); >0 — только старше N сек. Возвращает true, если что-то убрали. */
function scrub_conn(&$st, $maxAge){
    if (!is_array($st)) return false;
    $changed = false;
    foreach (array_keys($st) as $k){
        $v = $st[$k];
        $isConn = (($v['state'] ?? '')==='error') && (mb_stripos((string)($v['msg'] ?? ''), 'связ') !== false);
        if ($isConn && ($maxAge <= 0 || (time() - (int)($v['ts'] ?? 0)) >= $maxAge)){ unset($st[$k]); $changed = true; }
    }
    return $changed;
}

/* ---- fetch one source: full RSS url OR a topic query (via Google News RSS, ru) ---- */
function fetch_source($src){
    $src = trim($src); if ($src === '') return [];
    $url = (stripos($src,'http')===0) ? $src
         : 'https://news.google.com/rss/search?q=' . rawurlencode($src) . '&hl=ru&gl=RU&ceid=RU:ru';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>'Mozilla/5.0']);
    $xml = curl_exec($ch); curl_close($ch);
    if (!$xml) return [];
    $sx = @simplexml_load_string($xml); if (!$sx) return [];
    $channel = $sx->channel ?? $sx; $items = [];
    foreach (($channel->item ?? []) as $it){
        $title = trim((string)$it->title); if ($title==='') continue;
        $items[] = [
            'title'=>$title,
            'link'=>trim((string)$it->link),
            'summary'=>mb_substr(trim(strip_tags((string)$it->description)), 0, 400),
            'source'=>trim((string)($it->source ?? ($channel->title ?? $src))),
            'pubDate'=>trim((string)$it->pubDate),
            'query'=>$src,
        ];
    }
    return $items;
}

/* ---- перевод технической ошибки Gemini в человеческий текст ----
   возвращает [state, сообщение, fatal]
   state:  limit  = кончился лимит именно у этой модели -> пробуем следующую
           error  = прочая проблема
   fatal:  true   = проблема общая (ключ/доступ), другие модели не помогут -> стоп */
function human_error($code, $resp){
    $j   = json_decode((string)$resp, true);
    $stat = $j['error']['status']  ?? '';
    $msg  = $j['error']['message'] ?? '';
    if ($code === 429 && (stripos($msg,'prepayment') !== false || stripos($msg,'credits are depleted') !== false || stripos($msg,'billing') !== false))
        return ['error', 'Кредиты/баланс проекта этого ключа исчерпаны — нужен биллинг или бесплатный ключ. Ключ пропускается.', true];
    if ($code === 429 || stripos($stat,'RESOURCE_EXHAUSTED') !== false)
        return ['limit', 'Исчерпан бесплатный лимит запросов у этой модели (на минуту или на сутки). Автоматически перехожу на следующую модель.', false];
    if ($code === 400 && (stripos($msg,'API key not valid') !== false || stripos($msg,'API_KEY_INVALID') !== false))
        return ['error', 'Неверный API-ключ. Проверь, что ключ скопирован полностью с aistudio.google.com/apikey.', true];
    if ($code === 400 && stripos($msg,'quota') !== false)
        return ['limit', 'Закончилась квота у этой модели. Перехожу на следующую.', false];
    if ($code === 403)
        return ['error', 'Доступ запрещён: ключ не активирован или у проекта нет прав. Включи «Generative Language API» в консоли Google для этого ключа.', true];
    if ($code === 404)
        return ['error', 'Такой модели нет (неверное название). Убери её из списка или проверь имя.', false];
    if ($code === 500 || $code === 503)
        return ['error', 'Сервер Gemini сейчас перегружен. Пробую другую модель / попробую позже.', false];
    if ($msg)
        return ['error', 'Ошибка Gemini: ' . mb_substr($msg, 0, 160), false];
    return ['error', "Непонятная ошибка связи с Gemini (код $code).", false];
}

/* Список категорий для промпта строим ИЗ $CATS (generate.php) — чтобы набор
   в промпте всегда совпадал с реальными категориями «не больше не меньше»
   (включая свои, добавленные через админку). */
/* срок по умолчанию для каждой категории — КОД (число+единица: m,h,d,w,M,y) */
function default_cat_timeframe(){
    // диапазон срока (от–до) для ИИ-категорий; у код-категорий срок = интервал их расписания
    return [
        'war_ru_ua'=>['min'=>'3d','max'=>'1M'], 'frontline'=>['min'=>'3d','max'=>'1M'], 'crypto_news'=>['min'=>'1d','max'=>'1M'],
        'world_geo'=>['min'=>'2w','max'=>'6M'], 'world_tech'=>['min'=>'1M','max'=>'1y'], 'world_econ'=>['min'=>'2w','max'=>'6M'],
        'ru_geo'=>['min'=>'2w','max'=>'6M'], 'ru_tech'=>['min'=>'1M','max'=>'1y'], 'ru_econ'=>['min'=>'1w','max'=>'3M'],
        'ru_internal'=>['min'=>'2w','max'=>'6M'], 'putin'=>['min'=>'1w','max'=>'3M'],
    ];
}
function is_tf_code($s){ return (bool)preg_match('/^\d+[smhdwMy]$/', (string)$s); }
/* диапазон срока категории [min,max] (из настроек, иначе дефолт); поддержка старого одиночного формата */
function cat_tf_range($code){
    static $rules=null, $def=null;
    if ($rules===null){ $c=cfg(); $rules=is_array($c['cat_timeframe']??null)?$c['cat_timeframe']:[]; $def=default_cat_timeframe(); }
    $r = $rules[$code] ?? null;
    $min = $max = null;
    if (is_array($r)){ $min = $r['min'] ?? null; $max = $r['max'] ?? null; }
    elseif (is_string($r) && is_tf_code($r)){ $min = $max = $r; }   // старый формат (одно значение)
    $d = $def[$code] ?? ['min'=>'1w','max'=>'3M'];
    if (!is_tf_code($min)) $min = $d['min'];
    if (!is_tf_code($max)) $max = $d['max'];
    if (parse_timeframe($min) > parse_timeframe($max)){ $t=$min; $min=$max; $max=$t; }  // min<=max
    return ['min'=>$min, 'max'=>$max];
}
function category_guide(){
    $lines = [];
    foreach (ai_cats() as $c){
        $r = cat_tf_range($c['code']);
        $lo = tf_label(parse_timeframe($r['min'])); $hi = tf_label(parse_timeframe($r['max']));
        $lines[] = '  ' . $c['code'] . ' — ' . $c['label'] . ' [срок: от ' . $lo . ' до ' . $hi . ']';
    }
    return implode("\n", $lines);
}
/* только ИИ-категории (новости не должны попадать в код-категории — рынки) */
function ai_cats(){
    global $CATS;
    return array_values(array_filter($CATS, fn($c)=>($c['type'] ?? 'ai')==='ai'));
}
function category_codes(){
    return implode('|', array_map(fn($c)=>$c['code'], ai_cats()));
}

/* ---- промпт «новость -> событие» (категории строятся из $CATS) ---- */
function build_event_prompt($title, $summary){
    $guide = category_guide();
    $codes = category_codes();
    $today = date('Y-m-d');
    $maxOpt = max(2, min(9, (int)(cfg()['market_options'] ?? 5)));   // ПОТОЛОК числа вариантов (не ровно, а «до»)
    return "Ты — редактор prediction-маркета (как Polymarket). По новости составь ОДИН чёткий вопрос-прогноз о будущем.\n"
        . "Сегодня: {$today}.\n"
        . "Новость: «{$title}». {$summary}\n\n"
        . "Категория события ДОЛЖНА быть строго ОДНИМ кодом из этого списка. В квадратных скобках у каждой — ПРАВИЛО СРОКА для этой категории, соблюдай его:\n"
        . $guide . "\n\n"
        . "Верни СТРОГО JSON, без пояснений и markdown:\n"
        . "{\"question\":\"...\",\"type\":\"open|closed\",\"options\":[\"...\"],\"options_en\":[\"scene per option\"],\"category\":\"{$codes}\",\"timeframe\":\"1d|1w|1M|3M|6M|1y\",\"resolve_date\":\"ГГГГ-ММ-ДД или пусто\",\"image_en\":\"short English scene\"}\n\n"
        . "Правила:\n"
        . "- Вопрос конкретный, проверяемый, о будущем (не о прошлом).\n"
        . "- category — строго один код из списка; выбери самый подходящий по теме.\n"
        . "- Если новость не подходит НИ под одну категорию — верни {\"skip\":true}.\n"
        . "- СРОК: если в новости названа КОНКРЕТНАЯ дата или событие (встреча, саммит, заседание, выборы, «до конца месяца», «16 августа») — заполни resolve_date конкретной датой (ГГГГ-ММ-ДД), к которой вопрос точно разрешится (обычно в день события или сразу после). Она ДОЛЖНА быть в будущем относительно сегодня.\n"
        . "- Если конкретной даты нет — оставь resolve_date пустым, а timeframe выбери РАЗУМНО В ДИАПАЗОНЕ срока категории (в скобках «от … до …»), под смысл новости (важнее/ближе — короче, дальше — длиннее).\n"
        . "- Делай РАЗВЁРНУТЫЕ, СОДЕРЖАТЕЛЬНЫЕ вопросы о СУТИ события: решения, действия, последствия, исходы, «кто/что/чем закончится». Есть ДВА основных вида:\n"
        . "  1) closed → вопрос с ответом ДА или НЕТ (случится ли событие, примут ли решение, произойдёт ли). options=[]. РОВНО ДВА исхода — без третьего, без «возможно».\n"
        . "  2) open с ВАРИАНТАМИ-ИСХОДАМИ (главный вид для развёрнутых вопросов) — конкретные взаимоисключающие СОДЕРЖАТЕЛЬНЫЕ исходы: кто победит/возглавит, какое решение примут, чем закончатся переговоры, какой сценарий развернётся, какую меру выберут. Ответы — осмысленные формулировки, а НЕ числа.\n"
        . "- ЧИСЛОВЫЕ вопросы про КУРС/ЦЕНУ/СТАВКУ/ИНДЕКС/КОТИРОВКУ («каким будет курс», «какая цена», «на сколько вырастет») делать ЗАПРЕЩЕНО — такие прогнозы УЖЕ есть в лайв-каналах (рынки). Не дублируй их. Если новость только про курс/цену актива и ничего содержательного нет — верни {\"skip\":true}.\n"
        . "- Числовые диапазоны допустимы РЕДКО и ТОЛЬКО для не-рыночных показателей, которых нет в рынках (напр. число принятых пакетов санкций, количество регионов/стран, число мест в парламенте) — и то, если вопрос по сути развёрнутый.\n"
        . "- КОЛИЧЕСТВО вариантов: type=closed → options=[] (строго ДА/НЕТ, без третьего). type=open → от 2 до {$maxOpt} вариантов. Это ПОТОЛОК, а не точное число: делай столько, сколько реально осмысленных исходов (можно 2, 3… — НЕ обязательно ровно {$maxOpt}).\n"
        . "- options_en — заполняй ТОЛЬКО для type=open: массив ТОЙ ЖЕ длины, что options. Картинки нужны ТОЛЬКО для СМЫСЛОВЫХ ответов (например «Да, останется у поста», «Нет, уволят», «Партия N», «Страна X признает»). Для таких — короткое английское описание (5–12 слов) фотореалистичной сцены, СООТВЕТСТВУЮЩЕЙ именно этому ответу: прочитай вопрос, выдели ключевые слова и подбери сцену под конкретный вариант (напр. «Партия N» → символика/лидер этой партии; «Да, останется у поста» → premier at podium). "
        . "ОБЯЗАТЕЛЬНО для ПОХОЖИХ ответов-направлений/действий (напр. «Снизит ставку» / «Оставит без изменений» / «Повысит ставку», или «Вырастет» / «Упадёт» / «Без изменений», или «Усилят» / «Смягчат»): НЕ давай всем один и тот же объект (одинаковое здание/фон — грубая ошибка). Каждая сцена ДОЛЖНА показывать именно ИСХОД своего варианта и явно отличаться от других: понижение/спад → big downward red arrow, falling line chart, red minus sign; повышение/рост → big upward green arrow, rising line chart, green plus sign; без изменений/статус-кво → flat horizontal line, equals sign, a paused calm scene; ужесточение → tightening/closed barrier; смягчение → open gate/relief. Свяжи объект со ТЕМОЙ вопроса, но покажи НАПРАВЛЕНИЕ этого конкретного ответа, а не общий фон. "
        . "Для вариантов-ЧИСЕЛ и ДИАПАЗОНОВ («выше 100», «90–95», «менее 30%», «ниже 90 ₽») картинка НЕ нужна — ставь для них пустую строку \"\" в options_en. Правила сцены те же, что для image_en (конкретика, без посторонних флагов, человек — только если явно назван).\n"
        . "- image_en — КОРОТКОЕ (5–12 слов) английское описание фотореалистичной сцены. "
        . "ГЛАВНОЕ ПРАВИЛО: возьми КЛЮЧЕВЫЕ СЛОВА вопроса (сам предмет/действие, о котором спрашивают) и переведи их в сцену БУКВАЛЬНО, НЕ заменяя общими словами. "
        . "Называй конкретные предметы/субъекты по-английски: «российские вооруженные силы» → Russian soldiers and military vehicles; «войска за рубежом» → soldiers deployed abroad; «ЛНР и ДНР / Донбасс» → Donetsk and Luhansk region, war-damaged city street; «нефть» → oil rig; «газ» → gas pipeline; «дрон» → military drone. "
        . "СТРОГО ЗАПРЕЩЕНО подменять суть вопроса ЮРИДИЧЕСКИМ/БЮРОКРАТИЧЕСКИМ механизмом (заседание, голосование, парламент, Совет Федерации, зал совещаний, подписание документа), если про сам этот орган прямо не спрашивают — показывай САМО действие/предмет, а не кабинет, где о нём принимают решение. Пример ошибки: «задействуют ли армию» → НЕ парламентский зал, а солдаты и техника. "
        . "ЗАПРЕЩЕНЫ расплывчатые формулировки: 'national flags', 'breakaway regions', 'delegates', 'government building', пустые залы с креслами — они дают случайные чужие флаги и безликие здания. Пиши конкретную видимую сцену. "
        . "ЧЕЛОВЕКА (имя латиницей) добавляй ТОЛЬКО если его имя/фамилия ЯВНО написаны в вопросе. СТРОГО ЗАПРЕЩЕНО вставлять Vladimir Putin (или любого человека), если его нет в тексте вопроса. "
        . "РАЗНООБРАЗЬ СЦЕНЫ — это ВАЖНО. НЕ рисуй на каждый экономический/финансовый вопрос одно и то же (ни «человек + пачки денег», ни одинаковое здание): это частая грубая ошибка. "
        . "НЕ ПОВТОРЯЙ одну и ту же сцену/здание для похожих вопросов — придумывай НОВУЮ под конкретную формулировку. Подбирай КОНКРЕТНУЮ сцену под показатель: "
        . "курс валют → currency exchange board with dollar and ruble rates; инфляция/цены → supermarket shelves with price tags; ВВП/экономика → industrial factory or shipping containers; нефть → oil rig; газ → gas pipeline; золото → gold bars; бюджет/налоги → tax documents and calculator. "
        . "БАНКИ и ЦБ → НЕ рисуй биржевые/СВЕЧНЫЕ ГРАФИКИ (это частая грубая ошибка). Показывай РЕАЛЬНЫЕ сцены: здание банка снаружи, вывеска банка, банкомат, отделение банка с клиентами, очередь в банке, банковские карты, окно кассы, хранилище/сейф, табло ставок по вкладам. Для ставки ЦБ можно: Elvira Nabiullina at a press conference podium (только если про неё/ЦБ), a big percent sign on a plain board, loan and mortgage documents on a desk, central bank building exterior. "
        . "СВЕЧНЫЕ/биржевые графики и торговые терминалы допустимы РЕДКО и ТОЛЬКО для чисто биржевых вопросов (акции, индексы, фондовый рынок) — НЕ для банков, ЦБ, вкладов, кредитов, инфляции. "
        . "Банкноты/деньги в кадре — РЕДКО и только если вопрос прямо про наличные/курс, НЕ в каждой карточке. Лицо человека — только если он ЯВНО назван в вопросе (см. выше). ПОВТОРЯТЬ одинаковую сцену для разных вопросов ЗАПРЕЩЕНО — каждая карточка должна отличаться по смыслу. "
        . "Другие опоры: выборы → ballot box and voters. "
        . "ДИПЛОМАТИЯ/переговоры/встреча: НИКОГДА не пиши обобщённо «two flags» / «flags» без стран — это даёт случайный (обычно американский) флаг. НАЗЫВАЙ КОНКРЕТНЫЕ страны из вопроса: напр. вопрос про Россию и Казахстан → «officials of Russia and Kazakhstan shaking hands, Russian and Kazakh flags». Если стороны не названы — рисуй БЕЗ флагов (handshake of two officials, meeting table with documents, без флагов вообще). "
        . "САНКЦИИ рисуй ПО СУТИ вопроса, а НЕ кораблём/портом: одобрение/пакет санкций ЕС → European Parliament chamber with EU flags and officials voting; санкции против банков / число подсанкционных банков → bank buildings and bank signboards in a business district; санкции на нефть/экспорт → oil terminal or tanker. Корабль/порт — ТОЛЬКО если в вопросе буквально про морской экспорт/торговлю. "
        . "ФЛАГИ и госсимволику показывай ТОЛЬКО тех стран/организаций, что ЯВНО названы в вопросе, и ОБЯЗАТЕЛЬНО называй их поимённо (Russian flag, Kazakh flag…). "
        . "СТРОГО ЗАПРЕЩЕН американский/US флаг и любая символика США, если в вопросе НЕ упомянуты США/Америка — модели часто ошибочно добавляют флаг США в сцены переговоров, НЕ допускай этого. Так же запрещены флаги любых стран, которых нет в вопросе. "
        . "Только визуальная сцена, без слов про проценты/ставки.\n"
        . "- Весь текст на русском языке, КРОМЕ image_en (оно на английском)."
        . gen_rules_suffix();
}
/* доп. правила генерации из админки (дописываются к промпту события) */
function gen_rules_suffix(){
    $c = cfg(); $extra = trim((string)($c['gen_prompt'] ?? ''));
    return $extra === '' ? '' : "\n\nДОПОЛНИТЕЛЬНЫЕ ПРАВИЛА (соблюдай строго):\n" . $extra;
}

/* ---- НИЗКОУРОВНЕВЫЙ вызов одной модели одним ключом: отдаёт сырой текст ---- */
function gemini_raw($prompt, $key, $model){
    $body = json_encode([
        'contents'=>[['parts'=>[['text'=>$prompt]]]],
        'generationConfig'=>['temperature'=>0.7, 'responseMimeType'=>'application/json'],
    ], JSON_UNESCAPED_UNICODE);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($key);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>40,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_POSTFIELDS=>$body]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);

    if ($resp === false || $code === 0)
        return ['ok'=>false, 'state'=>'error', 'fatal'=>false,
                'error'=>'Нет связи с сервером Gemini (' . ($cerr ?: 'сеть недоступна') . ').'];
    if ($code !== 200){
        [$state, $msg, $fatal] = human_error($code, $resp);
        return ['ok'=>false, 'state'=>$state, 'fatal'=>$fatal, 'error'=>$msg];
    }
    $data = json_decode($resp, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if ($text === ''){
        $reason = $data['candidates'][0]['finishReason'] ?? ($data['promptFeedback']['blockReason'] ?? '');
        return ['ok'=>false, 'state'=>'error', 'fatal'=>false,
                'error'=>'Модель не вернула ответ' . ($reason ? " (причина: $reason)" : '') . '.'];
    }
    $um = $data['usageMetadata'] ?? [];
    $usage = ['in'=>(int)($um['promptTokenCount'] ?? 0), 'out'=>(int)($um['candidatesTokenCount'] ?? 0), 'total'=>(int)($um['totalTokenCount'] ?? 0)];
    return ['ok'=>true, 'text'=>$text, 'usage'=>$usage];
}

/* ---- перебор КЛЮЧ × МОДЕЛЬ для ЛЮБОГО промпта: первая рабочая пара выигрывает ----
   Пишет статус каждого ключа/модели (зелёный/красный в админке). Отдаёт сырой текст. */
function gemini_fallback($prompt, $kind='event'){
    $c = cfg();
    $allKeys = array_values(array_filter(array_map('trim', $c['gemini_keys'] ?: [])));
    if (!$allKeys) return ['ok'=>false, 'error'=>'Не задан ни один Gemini API-ключ. Добавь ключ и нажми «Добавить».'];
    $mstatus = is_array($c['model_status'] ?? null) ? $c['model_status'] : [];
    $kstatus = is_array($c['key_status'] ?? null)   ? $c['key_status']   : [];
    // ИСКЛЮЧАЕМ временно «лежачие» ключи из оборота (кулдаун), возвращаем после восстановления:
    //   лимит  -> пропускаем ~70 сек (минутный лимит успеет сброситься)
    //   ошибка -> пропускаем ~10 мин (битый/нет доступа)
    $now = time(); $keys = [];
    foreach ($allKeys as $k){
        $st = $kstatus[key_tail($k)] ?? null;
        if (is_array($st)){
            $age = $now - (int)($st['ts'] ?? 0);
            if (($st['state'] ?? '')==='limit' && $age < 70)  continue;
            if (($st['state'] ?? '')==='error' && $age < 600) continue;
        }
        $keys[] = $k;
    }
    if (!$keys) $keys = $allKeys;   // если все в кулдауне — всё равно пробуем всех (вдруг ожили)
    // РОТАЦИЯ ПО КРУГУ среди рабочих ключей: каждый вызов начинаем со следующего.
    $rr = (int)($c['key_rr'] ?? 0) % count($keys);
    $c['key_rr'] = ($rr + 1) % count($keys);
    if ($rr > 0) $keys = array_merge(array_slice($keys, $rr), array_slice($keys, 0, $rr));
    $models = array_values(array_filter($c['gemini_models'] ?: [])); if (!$models) $models = ['gemini-flash-lite-latest'];
    $lastMsg = '';
    foreach ($keys as $key){
        $kt = key_tail($key); $keyBad = false;
        foreach ($models as $m){
            $r = gemini_raw($prompt, $key, $m);
            if ($r['ok']){
                scrub_conn($mstatus, 0); scrub_conn($kstatus, 0);   // сеть жива — убрать ложные «нет связи» у остальных
                $mstatus[$m]  = ['state'=>'ok', 'msg'=>'работает', 'ts'=>time()];
                $kstatus[$kt] = ['state'=>'ok', 'msg'=>'работает', 'ts'=>time()];
                $c['model_status']=$mstatus; $c['key_status']=$kstatus;
                $c['active_model']=$m; $c['active_key']=$kt; $c['last_error']=''; cfg_save($c);
                record_usage($kind, $r['usage'] ?? []);   // учёт реального расхода
                return ['ok'=>true, 'text'=>$r['text'], 'model'=>$m];
            }
            $lastMsg = $r['error'];
            if (!empty($r['fatal'])){ $kstatus[$kt] = ['state'=>'error', 'msg'=>$r['error'], 'ts'=>time()]; $keyBad = true; break; }
            $mstatus[$m] = ['state'=>$r['state'], 'msg'=>$r['error'], 'ts'=>time()];
        }
        if (!$keyBad) $kstatus[$kt] = ['state'=>'limit', 'msg'=>'все модели у этого ключа сейчас недоступны (лимит)', 'ts'=>time()];
    }
    $c['model_status']=$mstatus; $c['key_status']=$kstatus; $c['active_model']=''; $c['active_key']='';
    $c['last_error'] = 'Все ключи и модели сейчас недоступны. Последняя ошибка: ' . $lastMsg;
    cfg_save($c);
    return ['ok'=>false, 'error'=>$c['last_error']];
}

/* ---- новость -> событие ---- */
function gemini_generate($title, $summary){
    $r = gemini_fallback(build_event_prompt($title, $summary), 'event');
    if (!$r['ok']) return ['ok'=>false, 'error'=>$r['error']];
    $ev = json_decode($r['text'], true);
    if (is_array($ev) && !empty($ev['skip']))
        return ['ok'=>false, 'skip'=>true, 'error'=>'Новость не подходит ни под одну категорию — пропущена.'];
    if (!is_array($ev) || empty($ev['question']))
        return ['ok'=>false, 'skip'=>true, 'error'=>'Модель не смогла составить корректный вопрос — пропущена.'];
    $aiCodes = array_map(fn($c)=>$c['code'], ai_cats());
    if (empty($ev['category']) || !in_array($ev['category'], $aiCodes, true))
        return ['ok'=>false, 'skip'=>true, 'error'=>'Категория вне ИИ-списка — новость пропущена.'];
    return ['ok'=>true, 'event'=>$ev, 'model'=>$r['model']];
}

/* ---- критерии важности по умолчанию (редактируются в админке) ---- */
function default_rank_prompt(){
    return "Ты — редактор prediction-маркета. Оцени, насколько каждая новость важна для рынка прогнозов.\n"
        . "ВЫСОКИЙ балл (8–10): крупный масштаб — страны, рынки, миллионы людей (война, санкции, ставка ЦБ, выборы, крупные законы, крупные сделки, обвалы/скачки цен); про будущее с неясным исходом (можно ставить); конкретное проверяемое событие.\n"
        . "СРЕДНИЙ (4–7): заметное, но локальное или с размытым исходом.\n"
        . "НИЗКИЙ (1–3, отсеивать): анонсы конференций, топ-подборки/списки, интервью и мнения, реклама, ретроспективы и «итоги года», мелкие корпоративные новости.";
}

/* ---- РАНЖИРОВАНИЕ ПАЧКОЙ: один запрос оценивает все заголовки и возвращает самые важные ----
   $items: [ ['title'=>..,'source'=>..], ... ] (индекс = позиция). Возвращает ranked[] по убыванию важности. */
function gemini_rank($items, $rankPrompt, $keep){
    if (!$items) return ['ok'=>true, 'ranked'=>[]];
    $lines = [];
    foreach ($items as $i=>$it){ $lines[] = $i . '. ' . mb_substr((string)$it['title'], 0, 180, 'UTF-8'); }
    $cap = max(1, (int)$keep);   // сколько важных вернуть (настраивается в админке)
    $codes = category_codes();
    $prompt = $rankPrompt . "\n\n"
        . "Вот список новостей (номер. заголовок):\n" . implode("\n", $lines) . "\n\n"
        . "Категории (выбери для каждой новости ОДИН код): {$codes}\n\n"
        . "Верни СТРОГО JSON-массив самых важных новостей, от самой важной к менее важной, максимум {$cap} штук. "
        . "ВАЖНО — РАЗНООБРАЗЬ ТЕМЫ: охвати как можно больше РАЗНЫХ категорий; из одной категории бери максимум 1–2 самых важных, а не весь список. Лучше 8 новостей из 8 разных тем, чем 8 про экономику. "
        . "МУСОР (низкий балл) НЕ включай вообще. Формат каждого элемента: "
        . "{\"i\":номер_из_списка,\"score\":1-10,\"c\":\"код_категории\",\"reason\":\"коротко почему важно (на русском)\"}. Верни только JSON-массив.";
    $r = gemini_fallback($prompt, 'rank');
    if (!$r['ok']) return ['ok'=>false, 'error'=>$r['error']];
    $arr = json_decode($r['text'], true);
    if (!is_array($arr)) return ['ok'=>false, 'error'=>'Модель вернула некорректный список важности.'];
    $ranked = [];
    foreach ($arr as $row){
        if (!is_array($row) || !isset($row['i'])) continue;
        $i = (int)$row['i'];
        if (!isset($items[$i])) continue;
        $ranked[] = [
            'i'=>$i,
            'score'=>max(1, min(10, (int)($row['score'] ?? 0))),
            'c'=>preg_replace('/[^a-z0-9_]/','', strtolower((string)($row['c'] ?? ''))),   // категория (для ротации)
            'reason'=>mb_substr((string)($row['reason'] ?? ''), 0, 200, 'UTF-8'),
            'title'=>$items[$i]['title'],
            'source'=>$items[$i]['source'] ?? '',
        ];
    }
    return ['ok'=>true, 'ranked'=>$ranked, 'model'=>$r['model']];
}

/* ---- build a feed event from AI output ($item — исходная новость, для ссылки на источник) ---- */
/* is_numeric_answer() определена в generate.php (общий файл, подключается выше) */

function build_event_from_ai($ai, $item, $forceCat = null){
    global $CAT_LABEL;
    $cat = $ai['category'] ?? 'world_geo';
    // жёстко: если задана категория ротации (из обхода по категориям главной) — берём её
    if ($forceCat !== null && isset($CAT_LABEL[$forceCat])) $cat = $forceCat;
    if (!isset($CAT_LABEL[$cat])) $cat = 'world_geo';
    // СРОК: 1) конкретная дата из новости; 2) иначе — выбор модели, зажатый в диапазон категории
    $rng = cat_tf_range($cat);
    $lo = parse_timeframe($rng['min']); $hi = parse_timeframe($rng['max']);
    $mtf = (string)($ai['timeframe'] ?? '');
    $secs = (is_tf_code($mtf) || is_numeric($mtf)) ? parse_timeframe($mtf) : $lo;
    $secs = max($lo, min($hi, $secs));   // держим срок в пределах [min,max] категории
    $tf = (string)$secs;
    $rd = trim((string)($ai['resolve_date'] ?? ''));
    $usedDate = '';
    if ($rd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd)){
        $ts = strtotime($rd . ' 23:59:59');
        if ($ts && $ts > time()){ $tf = (string)($ts - time()); $usedDate = $rd; }  // точная дата из новости перебивает
    }
    $opts   = is_array($ai['options'] ?? null) ? array_values($ai['options']) : [];
    $optsEn = is_array($ai['options_en'] ?? null) ? array_values($ai['options_en']) : [];
    // если варианты по сути ДА/НЕТ (модель добавила лишний третий и т.п.) — это закрытый вопрос
    $yesno = false;
    foreach ($opts as $l){ if (in_array(mb_strtolower(trim((string)$l)), ['да','нет','yes','no'], true)){ $yesno = true; break; } }
    $type = ((($ai['type'] ?? '')==='closed') || empty($opts) || $yesno) ? 'closed' : 'open';
    $e = ev_base($cat, $type, $tf);
    if ($usedDate !== '') $e['resolve_date'] = $usedDate;   // для наглядности: дата взята из новости
    $e['question'] = (string)$ai['question'];
    $e['source'] = 'news';
    $e['news_link']   = $item['link']   ?? '';   // ссылка на реальный источник
    $e['news_source'] = $item['source'] ?? '';   // название издания (для текста ссылки)
    $e['news_title']  = $item['title']  ?? '';    // исходный заголовок новости
    $e['image_en']    = trim((string)($ai['image_en'] ?? ''));   // англ. описание сцены для фото-картинки
    // ЛОГО компаний новостям НЕ подставляем: в вопросе часто несколько субъектов
    // (напр. «США… Nvidia и Palantir…») и подстановка лого одного из них вводит в заблуждение.
    // Новости всегда генерируют картинку-сцену под весь вопрос (image_en). Лого — только для лайв-каналов.
    $e['image'] = img_badge($CAT_LABEL[$cat], '#243b53');   // полное название категории (заглушка-подложка)
    if ($type === 'open'){
        $maxOpt = max(2, min(9, (int)(cfg()['market_options'] ?? 5)));   // «до N» — не ровно N
        $opts = array_slice($opts, 0, $maxOpt);                          // режем лишние варианты
        // ЕДИНООБРАЗИЕ: если ВСЕ варианты числовые/диапазоны — картинок нет НИ У КОГО;
        // если есть хоть один смысловой — картинки у ВСЕХ (не бывает «один с фото, другой без»).
        $allNumeric = true;
        foreach ($opts as $l){ if (!is_numeric_answer((string)$l)){ $allNumeric = false; break; } }
        $o = [];
        foreach ($opts as $idx=>$label){
            $opt = ['label'=>(string)$label, 'price'=>pct(8,45)];
            if (!$allNumeric){                                             // числа 1-2/3-4/5+ → без картинок
                // 1) в ответе есть страна/компания с лого («Китай», «Газпром») → берём лого (только ответам)
                $lg = company_logo_for((string)$label, true);
                if ($lg){
                    $opt['logo'] = $lg;
                } else {
                    $scene = trim((string)($optsEn[$idx] ?? ''));
                    if ($scene !== '') $opt['image_en'] = $scene;          // 2) иначе — сцена под смысл ответа -> фото
                    else $opt['image'] = img_badge((string)$label, '#2b3550'); // 3) нет сцены -> бейдж
                }
            }
            $o[] = $opt;
        }
        $e['options'] = $o;
    } else {
        // закрытый — РОВНО два: ДА/НЕТ (лишние варианты отброшены)
        $yes = pct(20,70);
        $e['options'] = [['label'=>'ДА','price'=>$yes], ['label'=>'НЕТ','price'=>100-$yes]];
    }
    return $e;
}

/* ---- one news item -> event (Gemini), push to feed ---- */
function news_to_event_do($item, $forceCat = null){
    global $DATA;
    $r = gemini_generate($item['title'], $item['summary'] ?? '');
    if (!$r['ok']) return ['ok'=>false, 'skip'=>!empty($r['skip']), 'error'=>$r['error']];
    $event = build_event_from_ai($r['event'], $item, $forceCat);
    $event['model'] = $r['model'];
    // нужна генерация картинки заголовка? (нет готового лого, но есть англ. сцена)
    if (empty($event['logo']) && !empty($event['image_en'])){
        // СТЕЙДЖИНГ «будет событием»: ждёт, пока сгенерится картинка, и только потом уходит в ленту
        $pf = PS_DATA . '/pending.json';
        $pend = jload($pf); if (!is_array($pend)) $pend = [];
        $event['staged_at'] = time();
        array_unshift($pend, $event);
        if (count($pend) > 100) $pend = array_slice($pend, 0, 100);
        jsave($pf, $pend);
        return ['ok'=>true, 'event'=>$event, 'model'=>$r['model'], 'staged'=>true];
    }
    // картинка не нужна (есть лого или просто бейдж) — публикуем в ленту сразу
    $events = jload($DATA); array_unshift($events, $event);
    if (count($events) > $GLOBALS['MAX_EVENTS']) $events = array_slice($events, 0, $GLOBALS['MAX_EVENTS']);
    jsave($DATA, $events);
    return ['ok'=>true, 'event'=>$event, 'model'=>$r['model'], 'staged'=>false];
}

/* ---- ПОЛНЫЙ ПРОГОН: ранжируем пул новостей по важности -> топ per_run превращаем в события ----
   Пишет снапшот (data/rank.json) для компактного дашборда: найдено / важных / что отобрано. */
function run_ranked_generation($want){
    global $NEWS;
    $c = cfg();
    $want = max(1, (int)$want);
    $news = jload($NEWS);
    // кандидаты = непереработанные (new). Дата = pubDate новости (когда опубликована), иначе время сбора.
    $now = time();
    $cand = [];
    foreach ($news as $idx=>$n){
        if (($n['status'] ?? '')==='new'){
            $ts = strtotime((string)($n['pubDate'] ?? '')) ?: strtotime((string)($n['created_at'] ?? '')) ?: 0;
            $cand[] = ['idx'=>$idx, 'title'=>$n['title'] ?? '', 'source'=>$n['source'] ?? '', 'ts'=>$ts];
        }
    }
    $found = count($cand);
    usort($cand, fn($a,$b)=>$b['ts'] <=> $a['ts']);   // свежие сверху
    // ПРЕДПОЧТЕНИЕ СВЕЖИМ: берём новости за последние 3 дня; если их слишком мало —
    // добираем из «кеша» (более старые непереработанные), чтобы было из чего постить.
    $fresh = array_values(array_filter($cand, fn($x)=>$x['ts'] >= $now - 3*86400));
    $minFresh = max(5, $want * 3);
    $cand = ($found > 0 && count($fresh) >= $minFresh) ? $fresh : $cand;
    $cand = array_slice($cand, 0, 200);
    $snap = ['ts'=>time(), 'found'=>$found, 'want'=>$want, 'important'=>0, 'made'=>0, 'selected'=>[], 'error'=>''];
    if (!$cand){ save_rank($snap); return $snap; }

    $rankPrompt = trim((string)($c['rank_prompt'] ?? '')) ?: default_rank_prompt();
    $keep = max($want, (int)($c['rank_keep'] ?? 10));   // хранить важных не меньше, чем делаем событий
    $rk = gemini_rank(array_map(fn($x)=>['title'=>$x['title'],'source'=>$x['source']], $cand), $rankPrompt, $keep);
    if (!$rk['ok']){ $snap['error']=$rk['error']; save_rank($snap); return $snap; }

    $ranked = $rk['ranked'];
    $snap['important'] = count($ranked);

    // --- РОТАЦИЯ ПО КАТЕГОРИЯМ: каждый прогон берёт СЛЕДУЮЩУЮ категорию по кругу ---
    $catOrder = array_map(fn($cc)=>$cc['code'], ai_cats());
    $nCat = max(1, count($catOrder));
    $ptr  = ((int)($c['gen_cat_rr'] ?? 0)) % $nCat;
    // очередь индексов $ranked, разложенных по категориям (важность внутри сохраняется)
    $queue = [];
    foreach ($ranked as $ri=>$row){
        $cat = in_array($row['c'], $catOrder, true) ? $row['c'] : ($catOrder[0] ?? '');
        $queue[$cat][] = $ri;
    }
    $evStatus = [];   // ri => event|skipped|error
    $made = 0;
    for ($slot = 0; $slot < $want; $slot++){
        $done = false;
        // до nCat шагов по кругу: ищем ближайшую категорию, где ещё есть новость
        for ($step = 0; $step < $nCat && !$done; $step++){
            $cat = $catOrder[($ptr + $step) % $nCat];
            while (!empty($queue[$cat])){
                $ri = array_shift($queue[$cat]);
                $gi = $cand[$ranked[$ri]['i']]['idx'] ?? null;
                if ($gi === null || !isset($news[$gi])) continue;
                $r = news_to_event_do($news[$gi], $cat);   // жёстко кладём в текущую категорию ротации
                if ($r['ok'])               { $news[$gi]['status']='used';    $evStatus[$ri]='event';   $made++; $ptr=($ptr+$step+1)%$nCat; $done=true; break; }
                if (!empty($r['skip']))     { $news[$gi]['status']='skipped'; $evStatus[$ri]='skipped'; continue; }   // пусто по теме — пробуем следующую в этой же категории
                $news[$gi]['status']='error'; $evStatus[$ri]='error'; $ptr=($ptr+$step+1)%$nCat; $done=true; break;   // ошибка API — засчитываем шаг, стоп по слоту
            }
        }
        if (!$done) break;   // новостей не осталось ни в одной категории
    }
    // сохранить указатель ротации, НЕ затирая статусы ключей/моделей, обновлённые при генерации
    $cc = cfg(); $cc['gen_cat_rr'] = $ptr; cfg_save($cc);

    // отчёт для дашборда
    foreach ($ranked as $ri=>$row){
        $snap['selected'][] = ['title'=>$row['title'], 'source'=>$row['source'], 'score'=>$row['score'], 'cat'=>$row['c'], 'reason'=>$row['reason'], 'status'=>($evStatus[$ri] ?? 'queued')];
    }
    jsave($NEWS, $news);
    $snap['made'] = $made;
    save_rank($snap);
    return $snap;
}

/* ---- собрать свежие новости со всех источников (общая функция для action=news_fetch и авто-тика) ----
   ВАЖНО: вызывается напрямую, БЕЗ http-запроса к самому себе — встроенный сервер php -S однопоточный,
   self-request завис бы (deadlock). */
function news_fetch_do(){
    global $NEWS;
    $c = cfg(); $news = jload($NEWS);
    $seen = []; foreach ($news as $n){ if (!empty($n['hash'])) $seen[$n['hash']] = true; }
    $added = 0;
    foreach (($c['sources'] ?? []) as $src){
        foreach (fetch_source($src) as $it){
            $hash = md5(($it['link'] ?: '') . '|' . $it['title']);
            if (isset($seen[$hash])) continue;
            $seen[$hash] = true;
            $news[] = $it + ['id'=>rid(), 'hash'=>$hash, 'status'=>'new', 'created_at'=>date('c')];
            $added++;
        }
    }
    // протухшие непереработанные новости (старше 7 дней по дате публикации) больше не постим
    $stale = time() - 7*86400;
    foreach ($news as &$n){
        if (($n['status'] ?? '')==='new'){
            $ts = strtotime((string)($n['pubDate'] ?? '')) ?: strtotime((string)($n['created_at'] ?? '')) ?: 0;
            if ($ts && $ts < $stale) $n['status'] = 'skipped';
        }
    } unset($n);
    usort($news, fn($a,$b)=>strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    $keep = max(10, (int)($c['news_keep'] ?? 300));
    if (count($news) > $keep) $news = array_slice($news, 0, $keep);
    jsave($NEWS, $news);
    return ['added'=>$added, 'total'=>count($news)];
}

/* ---------- router (skipped when this file is included as a library, e.g. tests) ---------- */
if (defined('NEWS_INCLUDE')) return;
$action = $_REQUEST['action'] ?? 'news_list';

if ($action==='config_get'){
    $c = cfg();
    // авто-очистка: устаревшие ошибки «нет связи» (старше 5 мин) убираем сами
    $ms = is_array($c['model_status'] ?? null) ? $c['model_status'] : [];
    $ks = is_array($c['key_status'] ?? null)   ? $c['key_status']   : [];
    $ch1 = scrub_conn($ms, 300); $ch2 = scrub_conn($ks, 300);
    if ($ch1 || $ch2){ $c['model_status'] = $ms; $c['key_status'] = $ks; cfg_save($c); }
    echo json_encode(['ok'=>true,'config'=>$c,'default_rank_prompt'=>default_rank_prompt(),'default_cat_timeframe'=>default_cat_timeframe()], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='config_save'){
    $c = cfg();
    if (isset($_REQUEST['rank_prompt'])) $c['rank_prompt'] = trim($_REQUEST['rank_prompt']);
    if (isset($_REQUEST['gen_prompt']))  $c['gen_prompt']  = trim($_REQUEST['gen_prompt']);
    if (isset($_REQUEST['cat_timeframe'])){
        $m = json_decode($_REQUEST['cat_timeframe'], true);
        if (is_array($m)){
            $clean = [];
            foreach ($m as $k=>$v){
                $k = preg_replace('/[^a-z0-9_]/','', strtolower((string)$k)); if ($k==='') continue;
                if (is_array($v)){
                    $mn = trim((string)($v['min'] ?? '')); $mx = trim((string)($v['max'] ?? ''));
                    if (is_tf_code($mn) && is_tf_code($mx)) $clean[$k] = ['min'=>$mn, 'max'=>$mx];   // диапазон
                } elseif (is_tf_code(trim((string)$v))){
                    $clean[$k] = trim((string)$v);   // старый формат — оставляем совместимость
                }
            }
            $c['cat_timeframe'] = $clean;
        }
    }
    if (isset($_REQUEST['rank_keep']))   $c['rank_keep']   = max(1, min(50, (int)$_REQUEST['rank_keep']));
    if (isset($_REQUEST['rpd_limit']))   $c['rpd_limit']   = max(1, (int)$_REQUEST['rpd_limit']);
    if (isset($_REQUEST['news_keep']))   $c['news_keep']   = max(10, min(5000, (int)$_REQUEST['news_keep']));
    if (isset($_REQUEST['feed_per_cat'])) $c['feed_per_cat'] = max(1, min(50, (int)$_REQUEST['feed_per_cat']));
    if (isset($_REQUEST['img_prompt']))   $c['img_prompt']   = trim($_REQUEST['img_prompt']);
    if (isset($_REQUEST['logo_prompt']))  $c['logo_prompt']  = trim($_REQUEST['logo_prompt']);
    if (isset($_REQUEST['live_active']))  $c['live_active']  = ($_REQUEST['live_active']==='1' || $_REQUEST['live_active']==='true');
    if (isset($_REQUEST['paused']))       $c['paused']       = ($_REQUEST['paused']==='1' || $_REQUEST['paused']==='true');
    // --- провайдеры картинок ---
    if (isset($_REQUEST['img_provider'])){
        $p = strtolower(trim($_REQUEST['img_provider']));
        if (in_array($p, ['pollinations','cloudflare','together','segmind'], true)) $c['img_provider'] = $p;
    }
    foreach (['cf_account','cf_token','cf_model','together_key','together_model','segmind_key','segmind_model'] as $k){
        if (isset($_REQUEST[$k])) $c[$k] = trim($_REQUEST[$k]);
    }
    if (isset($_REQUEST['market_options'])) $c['market_options'] = max(2, min(9, (int)$_REQUEST['market_options']));
    if (isset($_REQUEST['market_opts_iv'])){
        $m = json_decode($_REQUEST['market_opts_iv'], true);
        if (is_array($m)){
            $clean = [];
            foreach ($m as $k=>$v){ $k = trim((string)$k); $v = (int)$v; if ($k!=='' && $v>=2 && $v<=9) $clean[$k] = $v; }
            $c['market_opts_iv'] = $clean;
        }
    }
    // ключи НЕ трогаем тут — ими управляют action=key_add / key_del (несколько ключей с fallback)
    if (isset($_REQUEST['models'])){
        $list = array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $_REQUEST['models']))));
        if ($list) $c['gemini_models'] = $list;
    }
    if (isset($_REQUEST['sources'])) $c['sources'] = array_values(array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $_REQUEST['sources'])))));
    foreach (['active','interval','per_run','auto_publish'] as $k){
        if (isset($_REQUEST[$k])){
            if ($k==='active'||$k==='auto_publish') $c['auto'][$k] = ($_REQUEST[$k]==='1'||$_REQUEST[$k]==='true');
            else $c['auto'][$k] = max(($k==='interval'?10:1), (int)$_REQUEST[$k]);
        }
    }
    cfg_save($c); echo json_encode(['ok'=>true,'config'=>$c], JSON_UNESCAPED_UNICODE); exit;
}

if ($action==='models_reset'){   // забыть накопленные статусы/ошибки моделей (например, лимит восстановился)
    $c = cfg(); $c['model_status'] = []; $c['active_model'] = ''; $c['last_error'] = ''; cfg_save($c);
    echo json_encode(['ok'=>true,'config'=>$c], JSON_UNESCAPED_UNICODE); exit;
}

/* ---- управление ключами: несколько ключей, перебор с fallback ---- */
if ($action==='key_add'){        // добавить ключ в конец списка (пробуются по порядку)
    $c = cfg();
    $k = trim($_REQUEST['gemini_key'] ?? ($_REQUEST['key'] ?? ''));
    if ($k === ''){ echo json_encode(['ok'=>false,'error'=>'Пустой ключ — вставь ключ в поле.']); exit; }
    $keys = array_values(array_filter(array_map('trim', (array)($c['gemini_keys'] ?: []))));
    if (in_array($k, $keys, true)){ echo json_encode(['ok'=>false,'error'=>'Такой ключ уже добавлен.','config'=>$c], JSON_UNESCAPED_UNICODE); exit; }
    $keys[] = $k;
    $c['gemini_keys'] = $keys;
    unset($c['gemini_key']);                       // убираем устаревшее одиночное поле
    $kt = key_tail($k);
    if (is_array($c['key_status'] ?? null)) unset($c['key_status'][$kt]);  // новый ключ — с чистого листа
    $c['last_error'] = '';
    cfg_save($c);
    echo json_encode(['ok'=>true,'config'=>$c], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='key_del'){        // удалить ключ по индексу (или по значению)
    $c = cfg();
    $keys = array_values(array_filter(array_map('trim', (array)($c['gemini_keys'] ?: []))));
    if (isset($_REQUEST['idx']) && is_numeric($_REQUEST['idx'])){
        $i = (int)$_REQUEST['idx'];
        if ($i >= 0 && $i < count($keys)) array_splice($keys, $i, 1);
    } else {
        $k = trim($_REQUEST['key'] ?? '');
        $keys = array_values(array_filter($keys, fn($x)=>$x !== $k));
    }
    $c['gemini_keys'] = $keys;
    unset($c['gemini_key']);
    cfg_save($c);
    echo json_encode(['ok'=>true,'config'=>$c], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='keys_reset'){     // забыть статусы ключей (например, суточный лимит восстановился)
    $c = cfg(); $c['key_status'] = []; $c['active_key'] = ''; $c['last_error'] = ''; cfg_save($c);
    echo json_encode(['ok'=>true,'config'=>$c], JSON_UNESCAPED_UNICODE); exit;
}

/* ---- ключи ПРОВАЙДЕРОВ КАРТИНОК: несколько на каждого, с фолбэком ---- */
if ($action==='img_key_add'){
    $c = cfg();
    $prov = strtolower(trim($_REQUEST['provider'] ?? ''));
    $field = ['cloudflare'=>'cf_keys','together'=>'together_keys','segmind'=>'segmind_keys'][$prov] ?? '';
    if ($field===''){ echo json_encode(['ok'=>false,'error'=>'неизвестный провайдер']); exit; }
    $list = is_array($c[$field] ?? null) ? array_values($c[$field]) : [];
    if ($prov==='cloudflare'){
        $acc = trim($_REQUEST['account'] ?? ''); $tok = trim($_REQUEST['key'] ?? '');
        if ($acc===''||$tok===''){ echo json_encode(['ok'=>false,'error'=>'нужны Account ID и Token']); exit; }
        foreach ($list as $e){ if (($e['token']??'')===$tok){ echo json_encode(['ok'=>false,'error'=>'такой токен уже есть','config'=>$c], JSON_UNESCAPED_UNICODE); exit; } }
        $list[] = ['account'=>$acc, 'token'=>$tok];
    } else {
        $k = trim($_REQUEST['key'] ?? '');
        if ($k===''){ echo json_encode(['ok'=>false,'error'=>'пустой ключ']); exit; }
        if (in_array($k, $list, true)){ echo json_encode(['ok'=>false,'error'=>'такой ключ уже есть','config'=>$c], JSON_UNESCAPED_UNICODE); exit; }
        $list[] = $k;
    }
    $c[$field] = $list; cfg_save($c);
    echo json_encode(['ok'=>true,'config'=>$c], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='img_key_del'){
    $c = cfg();
    $prov = strtolower(trim($_REQUEST['provider'] ?? ''));
    $field = ['cloudflare'=>'cf_keys','together'=>'together_keys','segmind'=>'segmind_keys'][$prov] ?? '';
    if ($field===''){ echo json_encode(['ok'=>false,'error'=>'неизвестный провайдер']); exit; }
    $list = is_array($c[$field] ?? null) ? array_values($c[$field]) : [];
    $i = (int)($_REQUEST['idx'] ?? -1);
    if ($i>=0 && $i<count($list)) array_splice($list, $i, 1);
    $c[$field] = $list; cfg_save($c);
    echo json_encode(['ok'=>true,'config'=>$c], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='img_keys_reset'){   // забыть статусы ключей картинок (лимиты восстановились)
    $f = PS_DATA . '/img_keys.json'; @file_put_contents($f, json_encode(['status'=>new stdClass(),'pref'=>new stdClass()]));
    echo json_encode(['ok'=>true]); exit;
}
if ($action==='img_status_get'){   // статусы ключей картинок + КТО РЕАЛЬНО будет генерить (для подсветки чипов)
    require_once __DIR__ . '/imglib.php';
    $f = PS_DATA . '/img_keys.json';
    $s = is_file($f) ? json_decode(file_get_contents($f), true) : [];
    $status = is_array($s['status'] ?? null) ? $s['status'] : [];
    // эффективный провайдер = первый в цепочке, у кого есть рабочий (не в кулдауне) ключ; иначе Pollinations
    $ic = img_cfg();
    $eff = 'pollinations'; $now = time();
    foreach (img_provider_chain($ic) as $p){
        if ($p === 'pollinations'){ $eff = 'pollinations'; break; }   // крайний запасной — всегда доступен
        $keys = img_provider_keys($p, $ic);
        if (!$keys) continue;                                          // нет ключей — пропускаем
        foreach ($keys as $k){
            $st = $status[img_key_tail($k)] ?? null;
            $ok = !$st;
            if ($st){ $age = $now - (int)($st['ts'] ?? 0); $ok = ($st['state']==='limit' ? $age>=120 : $age>=600); }
            if ($ok){ $eff = $p; break 2; }                            // нашли рабочий ключ у этого провайдера
        }
    }
    echo json_encode(['ok'=>true,'status'=>($status ?: new stdClass()),'pref'=>($s['pref'] ?? new stdClass()),'last_provider'=>($s['last_provider'] ?? ''),'effective_provider'=>$eff], JSON_UNESCAPED_UNICODE); exit;
}

if ($action==='news_list'){ echo json_encode(['ok'=>true,'news'=>jload($NEWS)], JSON_UNESCAPED_UNICODE); exit; }

if ($action==='news_fetch'){
    $r = news_fetch_do();
    echo json_encode(['ok'=>true,'added'=>$r['added'],'total'=>$r['total']]); exit;
}

if ($action==='news_status'){
    $id=$_REQUEST['id']??''; $st=$_REQUEST['status']??'new'; $news=jload($NEWS);
    foreach ($news as &$n){ if ($n['id']===$id) $n['status']=$st; } unset($n);
    jsave($NEWS,$news); echo json_encode(['ok'=>true]); exit;
}
if ($action==='news_del'){
    $id=$_REQUEST['id']??'';
    jsave($NEWS, array_values(array_filter(jload($NEWS), fn($n)=>$n['id']!==$id)));
    echo json_encode(['ok'=>true]); exit;
}
if ($action==='news_clear'){   // удалить ВСЕ прочитанные новости (очистить очередь)
    jsave($NEWS, []);
    save_rank([]);
    echo json_encode(['ok'=>true]); exit;
}

/* ---- учёт расхода Gemini ---- */
if ($action==='usage_get'){    // сколько запросов/токенов потрачено сегодня (реальные числа)
    echo json_encode(['ok'=>true,'usage'=>usage_get()], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='usage_reset'){  // обнулить счётчик за сегодня
    $all = jload(usage_file()); if (!is_array($all)) $all = [];
    unset($all[usage_day()]); jsave(usage_file(), $all);
    echo json_encode(['ok'=>true,'usage'=>usage_get()], JSON_UNESCAPED_UNICODE); exit;
}

/* ---- ранжирование по важности ---- */
if ($action==='rank_get'){     // снапшот последнего прогона (для дашборда)
    echo json_encode(['ok'=>true,'rank'=>get_rank()], JSON_UNESCAPED_UNICODE); exit;
}
if ($action==='rank_now'){     // прогнать сейчас: собрать -> оценить -> сделать топ per_run (для кнопки/теста)
    $c = cfg();
    news_fetch_do();
    $snap = run_ranked_generation((int)($c['auto']['per_run'] ?? 3));
    echo json_encode(['ok'=>true,'rank'=>$snap], JSON_UNESCAPED_UNICODE); exit;
}

if ($action==='news_to_event'){
    $id=$_REQUEST['id']??''; $news=jload($NEWS); $item=null;
    foreach ($news as &$n){ if ($n['id']===$id){ $item=$n; break; } } unset($n);
    if (!$item){ echo json_encode(['ok'=>false,'error'=>'news not found']); exit; }
    $r = news_to_event_do($item);
    $newSt = $r['ok'] ? 'used' : (!empty($r['skip']) ? 'skipped' : 'error');
    foreach ($news as &$n){ if ($n['id']===$id) $n['status']=$newSt; } unset($n); jsave($NEWS,$news);
    echo json_encode($r, JSON_UNESCAPED_UNICODE); exit;
}

/* auto pass — called by the worker: собрать -> оценить важность -> сделать топ per_run */
if ($action==='news_tick'){
    $c = cfg();
    if (!empty($c['paused'])){ echo json_encode(['ok'=>true,'skipped'=>'paused']); exit; }   // экстренный стоп
    if (empty($c['auto']['active'])){ echo json_encode(['ok'=>true,'skipped'=>'auto off']); exit; }
    $now = time();
    if ($now - (int)($c['auto']['last_run'] ?? 0) < max(10,(int)$c['auto']['interval'])){ echo json_encode(['ok'=>true,'skipped'=>'not due']); exit; }
    $c['auto']['last_run'] = $now; cfg_save($c);
    news_fetch_do();   // прямой вызов, без self-http (php -S однопоточный)
    $snap = run_ranked_generation((int)$c['auto']['per_run']);
    echo json_encode(['ok'=>true,'generated'=>$snap['made'],'found'=>$snap['found'],'important'=>$snap['important']]); exit;
}

echo json_encode(['ok'=>false,'error'=>'unknown action']);
