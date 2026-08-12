<?php
/* Общая библиотека генерации картинок: несколько провайдеров, у каждого НЕСКОЛЬКО ключей
   с фолбэком (если один упёрся в лимит/отвалился — берётся следующий), + конвертация в WebP.
   Используется кэширующим прокси img.php и тестом в админке (generate.php). */

// папка данных (можно переопределить через PS_DATA_DIR — используется тестами для изоляции)
if (!defined('PS_DATA')) define('PS_DATA', getenv('PS_DATA_DIR') ?: __DIR__ . '/../data');

if (!function_exists('img_cfg')) {

/* настройки картинок из общего config.json (+ миграция старых одиночных ключей в массивы) */
function img_cfg(){
    $f = PS_DATA . '/config.json';
    $c = is_file($f) ? json_decode(file_get_contents($f), true) : [];
    if (!is_array($c)) $c = [];
    $c += [
        'img_provider'  => 'pollinations',
        'cf_keys' => [], 'cf_model' => '@cf/black-forest-labs/flux-1-schnell',
        'together_keys' => [], 'together_model' => 'black-forest-labs/FLUX.1-schnell-Free',
        'segmind_keys' => [], 'segmind_model' => 'fast-flux-schnell',
        'cf_account'=>'', 'cf_token'=>'', 'together_key'=>'', 'segmind_key'=>'',
    ];
    if (empty($c['cf_keys'])       && !empty($c['cf_token']))     $c['cf_keys']       = [['account'=>$c['cf_account'], 'token'=>$c['cf_token']]];
    if (empty($c['together_keys']) && !empty($c['together_key'])) $c['together_keys'] = [$c['together_key']];
    if (empty($c['segmind_keys'])  && !empty($c['segmind_key']))  $c['segmind_keys']  = [$c['segmind_key']];
    return $c;
}

/* нормализованный список ключей провайдера: cloudflare => [['account','token'],...], иначе => [str,...] */
function img_provider_keys($provider, $cfg){
    if ($provider==='cloudflare'){
        $free = []; $paid = [];
        foreach ((array)($cfg['cf_keys'] ?? []) as $e){
            if (!is_array($e) || empty($e['token'])) continue;
            $k = ['account'=>$e['account']??'', 'token'=>$e['token'], 'paid'=>!empty($e['paid'])];
            // Платные аккаунты — в конец: сначала выбираем бесплатные суточные
            // нормы, и только когда все они исчерпаны, тратим деньги.
            if ($k['paid']) $paid[] = $k; else $free[] = $k;
        }
        return array_merge($free, $paid);
    }
    $field = $provider==='together' ? 'together_keys' : ($provider==='segmind' ? 'segmind_keys' : '');
    if ($field==='') return [];
    return array_values(array_filter(array_map('trim', (array)($cfg[$field] ?? []))));
}

/* хвост ключа (для статуса/подсветки) */
function img_key_tail($key){
    $s = is_array($key) ? ($key['token'] ?? '') : (string)$key;
    return substr(trim($s), -6);
}

/* --- статусы ключей (data/img_keys.json), чтобы не долбить упавший ключ каждый раз --- */
function img_status_file(){ return PS_DATA . '/img_keys.json'; }
function img_status_load(){
    $s = is_file(img_status_file()) ? json_decode(file_get_contents(img_status_file()), true) : [];
    if (!is_array($s)) $s = [];
    $s += ['status'=>[], 'pref'=>[]];
    if (!is_array($s['status'])) $s['status']=[]; if (!is_array($s['pref'])) $s['pref']=[];
    return $s;
}
function img_status_save($s){ @file_put_contents(img_status_file(), json_encode($s, JSON_UNESCAPED_UNICODE)); }
function img_cooldown($state){ return $state==='limit' ? 120 : 600; }   // лимит быстро восстанавливается, ошибка — дольше
/* успех: снять ошибку с ключа и СДВИНУТЬ указатель на СЛЕДУЮЩИЙ (ротация по кругу — нагрузка размазывается) */
function img_status_ok(&$s, $tail, $provider, $idx, $total){ unset($s['status'][$tail]); $s['pref'][$provider] = ($total>0) ? (($idx+1) % $total) : 0; }
function img_status_fail(&$s, $tail, $err){
    $state = (stripos($err,'429')!==false || stripos($err,'too many')!==false || stripos($err,'rate')!==false) ? 'limit' : 'error';
    $s['status'][$tail] = ['state'=>$state, 'msg'=>substr($err,0,180), 'ts'=>time()];
}

/* низкоуровневый POST JSON -> [body, http_code, content_type] */
function _img_post($url, $headers, $payload){
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => is_string($payload) ? $payload : json_encode($payload),
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false) return ['', 0, 'curl: ' . $cerr];
    return [$body, $code, (string)$ct];
}

/* --- Pollinations (без ключа, GET) --- */
function _img_pollinations($prompt, $w, $seed, &$err){
    $url = 'https://image.pollinations.ai/prompt/' . rawurlencode($prompt)
         . '?width=' . $w . '&height=' . $w . '&nologo=true&seed=' . $seed;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_USERAGENT=>'Mozilla/5.0']);
    $img = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
    if ($img && $code===200 && strpos((string)$ct,'image')!==false) return $img;
    $err = 'Pollinations HTTP ' . $code; return false;
}

/* --- Cloudflare Workers AI --- */
function _img_cloudflare($prompt, $w, $seed, $account, $token, $model, &$err){
    $model = $model ?: '@cf/black-forest-labs/flux-1-schnell';
    if ($account==='' || $token===''){ $err='Cloudflare: нет account/token'; return false; }
    $url = "https://api.cloudflare.com/client/v4/accounts/{$account}/ai/run/{$model}";
    [$body,$code,$ct] = _img_post($url, ["Authorization: Bearer {$token}", "Content-Type: application/json"], ['prompt'=>$prompt, 'steps'=>4]);
    if ($code!==200){ $err='Cloudflare HTTP '.$code.' '.substr(strip_tags((string)$body),0,160); return false; }
    if (strpos($ct,'application/json')!==false){
        $j = json_decode($body, true); $b64 = $j['result']['image'] ?? '';
        if ($b64===''){ $err='Cloudflare: пустой ответ'; return false; }
        $img = base64_decode($b64);
        if ($img===false || strlen($img)<100){ $err='Cloudflare: не декодируется'; return false; }
        return $img;
    }
    if (strpos($ct,'image')!==false) return $body;
    $err='Cloudflare: формат '.$ct; return false;
}

/* --- Together AI --- */
function _img_together($prompt, $w, $seed, $key, $model, &$err){
    $model = $model ?: 'black-forest-labs/FLUX.1-schnell-Free';
    if ($key===''){ $err='Together: нет ключа'; return false; }
    [$body,$code,$ct] = _img_post('https://api.together.xyz/v1/images/generations',
        ["Authorization: Bearer {$key}", "Content-Type: application/json"],
        ['model'=>$model, 'prompt'=>$prompt, 'width'=>$w, 'height'=>$w, 'steps'=>4, 'n'=>1, 'response_format'=>'b64_json', 'seed'=>$seed]);
    if ($code!==200){ $err='Together HTTP '.$code.' '.substr(strip_tags((string)$body),0,160); return false; }
    $j = json_decode($body, true); $d = $j['data'][0] ?? [];
    if (!empty($d['b64_json'])){ $img = base64_decode($d['b64_json']); if ($img!==false && strlen($img)>100) return $img; }
    if (!empty($d['url'])){ $img = @file_get_contents($d['url']); if ($img && strlen($img)>100) return $img; }
    $err='Together: пустой ответ'; return false;
}

/* --- Segmind --- */
function _img_segmind($prompt, $w, $seed, $key, $model, &$err){
    $model = $model ?: 'fast-flux-schnell';
    if ($key===''){ $err='Segmind: нет ключа'; return false; }
    [$body,$code,$ct] = _img_post("https://api.segmind.com/v1/{$model}",
        ["x-api-key: {$key}", "Content-Type: application/json"],
        ['prompt'=>$prompt, 'steps'=>4, 'seed'=>$seed, 'img_width'=>$w, 'img_height'=>$w, 'aspect_ratio'=>'1:1']);
    if ($code!==200){ $err='Segmind HTTP '.$code.' '.substr(strip_tags((string)$body),0,160); return false; }
    if (strpos($ct,'image')!==false) return $body;
    $j = json_decode($body, true);
    if (is_array($j)){ $b64 = $j['image'] ?? ($j['images'][0] ?? ''); if ($b64){ $img = base64_decode($b64); if ($img && strlen($img)>100) return $img; } }
    $err='Segmind: формат '.$ct; return false;
}

/* попытка с ОДНИМ конкретным ключом */
function img_try_key($provider, $prompt, $w, $seed, $key, $cfg, &$err){
    switch ($provider){
        case 'cloudflare': return _img_cloudflare($prompt,$w,$seed, $key['account']??'', $key['token']??'', $cfg['cf_model']??'', $err);
        case 'together':   return _img_together($prompt,$w,$seed, (string)$key, $cfg['together_model']??'', $err);
        case 'segmind':    return _img_segmind($prompt,$w,$seed, (string)$key, $cfg['segmind_model']??'', $err);
    }
    $err='неизвестный провайдер'; return false;
}

/* главный вход: генерирует картинку выбранным провайдером, перебирая ключи с фолбэком */
function img_generate_raw($prompt, $w, $seed, $provider, $cfg, &$err){
    if ($provider==='pollinations') return _img_pollinations($prompt, $w, $seed, $err);

    $keys = img_provider_keys($provider, $cfg);
    if (!$keys){ $err = 'Нет ключей для «'.$provider.'» — добавь в админке.'; return false; }

    $store = img_status_load();
    $n = count($keys);
    $pref = (int)($store['pref'][$provider] ?? 0); if ($pref<0 || $pref>=$n) $pref = 0;
    // Начинаем с последнего рабочего — но только среди бесплатных. Иначе,
    // один раз отработав, платный аккаунт становится предпочтительным и
    // деньги тратятся, пока бесплатные суточные нормы стоят нетронутыми.
    if (!empty($keys[$pref]['paid'])) $pref = 0;
    $order = []; for ($j=0;$j<$n;$j++) $order[] = ($pref + $j) % $n;
    $now = time(); $skipped = []; $lastErr = '';

    // проход 1: пропускаем ключи в кулдауне (недавно упавшие)
    foreach ($order as $idx){
        $tail = img_key_tail($keys[$idx]);
        $st = $store['status'][$tail] ?? null;
        if ($st && ($now - (int)($st['ts']??0)) < img_cooldown($st['state']??'')){ $skipped[] = $idx; continue; }
        $e=''; $raw = img_try_key($provider, $prompt, $w, $seed, $keys[$idx], $cfg, $e);
        if ($raw !== false && $raw){ img_status_ok($store,$tail,$provider,$idx,$n); img_status_save($store); return $raw; }
        img_status_fail($store,$tail,$e); $lastErr = $e; img_status_save($store);
    }
    // проход 2: пробуем пропущенные (вдруг лимит уже восстановился)
    foreach ($skipped as $idx){
        $tail = img_key_tail($keys[$idx]);
        $e=''; $raw = img_try_key($provider, $prompt, $w, $seed, $keys[$idx], $cfg, $e);
        if ($raw !== false && $raw){ img_status_ok($store,$tail,$provider,$idx,$n); img_status_save($store); return $raw; }
        img_status_fail($store,$tail,$e); $lastErr = $e; img_status_save($store);
    }
    $err = $lastErr ?: ('Все ключи «'.$provider.'» недоступны');
    return false;
}

/* порядок провайдеров для авто-фолбэка: провайдеры С КЛЮЧАМИ (Cloudflare главный — быстрый и
   качественный) идут ПЕРВЫМИ, Pollinations (без ключа, медленный) — крайний запасной.
   Кулдаун упавших ключей (img_generate_raw) сам вернёт работу на Cloudflare, когда ключ оживёт. */
function img_provider_chain($cfg){
    $order = [];
    if (!empty($cfg['cf_keys']))       $order[] = 'cloudflare';
    if (!empty($cfg['together_keys'])) $order[] = 'together';
    if (!empty($cfg['segmind_keys']))  $order[] = 'segmind';
    // если явно выбран конкретный keyed-провайдер — поднимаем его в начало
    $active = $cfg['img_provider'] ?? '';
    if ($active !== '' && $active !== 'pollinations'){
        $order = array_merge([$active], array_values(array_diff($order, [$active])));
    }
    $order[] = 'pollinations';          // крайний запасной — всегда доступен
    $seen = []; $out = [];
    foreach ($order as $p){ if ($p!=='' && empty($seen[$p])){ $seen[$p]=1; $out[]=$p; } }
    return $out;
}

/* сгенерировать с АВТО-ПЕРЕХОДОМ между сервисами: если весь провайдер отвалился — берём следующий */
function img_generate_chain($prompt, $w, $seed, $cfg, &$err, &$usedProvider=null){
    $errs = [];
    foreach (img_provider_chain($cfg) as $p){
        $e = '';
        $raw = img_generate_raw($prompt, $w, $seed, $p, $cfg, $e);
        if ($raw !== false && $raw){
            $usedProvider = $p;
            $st = img_status_load(); $st['last_provider'] = $p; $st['last_provider_ts'] = time(); img_status_save($st);
            return $raw;
        }
        $errs[] = $p . ' (' . $e . ')';
    }
    $err = 'все сервисы недоступны: ' . implode(' | ', $errs);
    return false;
}

/* конвертация сырых байтов в WebP; возвращает webp-байты или false */
function img_to_webp($raw){
    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) return false;
    $im = @imagecreatefromstring($raw);
    if ($im === false) return false;
    if (function_exists('imagepalettetotruecolor')) @imagepalettetotruecolor($im);
    ob_start(); imagewebp($im, null, 82); $out = ob_get_clean();
    imagedestroy($im);
    return ($out && strlen($out) > 100) ? $out : false;
}

} // end guard
