<?php
/* Кэширующий WebP-прокси для картинок событий.
   Берёт картинку у выбранного провайдера ОДИН раз, переформатирует в WebP,
   сохраняет в data/genimg/. Дальше отдаётся готовый .webp — провайдер не дёргается.
   Параметры: q = текст сцены, w = размер, seed, provider (необязательно; иначе из конфига). */

require __DIR__ . '/imglib.php';

$q    = trim($_GET['q'] ?? '');
$w    = min(1024, max(64, (int)($_GET['w'] ?? 512)));
$seed = (int)($_GET['seed'] ?? 0);
if ($q === '') { http_response_code(400); exit; }

$cfg = img_cfg();
// провайдер явно в URL (тест конкретного) -> только он; иначе -> активный + авто-фолбэк по цепочке
$explicit = isset($_GET['provider']) && trim($_GET['provider']) !== '';
$provider = strtolower(trim($_GET['provider'] ?? $cfg['img_provider'] ?? 'pollinations'));
if (!in_array($provider, ['pollinations','cloudflare','together','segmind'], true)) $provider = 'pollinations';

$dir = PS_DATA . '/genimg';
if (!is_dir($dir)) @mkdir($dir, 0777, true);

/* ключ кэша БЕЗ провайдера — стабилен при смене провайдера (картинка не сиротится, не перегенерится).
   Исключение: явный тест конкретного провайдера (?provider=) держим в отдельном кэше. */
$key = $explicit
    ? substr(md5($provider . '|' . $q . '|' . $w . '|' . $seed), 0, 20)
    : substr(md5($q . '|' . $w . '|' . $seed), 0, 20);
$web = $dir . '/w_' . $key . '.webp';

function serve_webp($path){
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* уже сконвертировано — отдаём из кэша, к провайдеру не ходим */
if (is_file($web) && filesize($web) > 0) serve_webp($web);

/* ЭКСТРЕННЫЙ СТОП: не генерим ничего нового, только отдаём из кэша (иначе 502 -> бейдж) */
if (!empty($cfg['paused'])) { http_response_code(502); exit; }

/* генерируем: явный провайдер — только он; иначе цепочка с авто-переходом между сервисами */
$err = '';
$used = $provider;
$raw = $explicit
    ? img_generate_raw($q, $w, $seed, $provider, $cfg, $err)
    : img_generate_chain($q, $w, $seed, $cfg, $err, $used);
if ($raw === false || !$raw) {
    http_response_code(502);           // на странице сработает фолбэк на бейдж
    header('X-Img-Error: ' . substr(preg_replace('/[^\x20-\x7e]/','',$err), 0, 200));
    exit;
}

/* конвертируем в WebP и кэшируем */
$webp = img_to_webp($raw);
if ($webp !== false) {
    file_put_contents($web, $webp);
    serve_webp($web);
}

/* если конвертация не вышла — отдаём оригинал как есть (не кэшируем в webp) */
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
echo $raw;
exit;
