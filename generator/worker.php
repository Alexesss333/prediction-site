<?php
/**
 * Background worker. Every ~10s pings both auto-tick endpoints:
 *   - generate.php?action=tick     (scheduled template auto-generation)
 *   - news.php?action=news_tick     (news -> Gemini -> event pipeline)
 * Runs forever alongside the web server inside the container.
 * Plain-host alternative: cron every minute -> php worker.php once
 */
$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8080/generator';
$urls = [ $base . '/generate.php?action=tick', $base . '/news.php?action=news_tick' ];
$once = (($argv[1] ?? '') === 'once');

// подключаем ядро, чтобы ПРЕ-ГЕНЕРИРОВАТЬ картинки прямо в процессе воркера
// (в кэш, не блокируя веб-сервер и не завися от IP-лимита у зрителя).
define('GEN_INCLUDE', true);
require_once __DIR__ . '/generate.php';

fwrite(STDERR, "[worker] base = $base" . ($once ? " (single pass)\n" : " (loop)\n"));

do {
    // ЭКСТРЕННЫЙ СТОП: читаем флаг прямо из файла — на паузе воркер НИЧЕГО не запускает
    $cfg = @json_decode(@file_get_contents(PS_DATA . '/config.json'), true);
    $paused = is_array($cfg) && !empty($cfg['paused']);
    if (!$paused){
        foreach ($urls as $u){
            $r = @file_get_contents($u);
            if ($r !== false && trim($r) !== '' && strpos($r, '"generated":0') === false && strpos($r, '"skipped"') === false)
                fwrite(STDERR, "[worker] " . basename(parse_url($u, PHP_URL_PATH)) . ": " . trim($r) . "\n");
        }
        // СТЕЙДЖИНГ: генерируем картинку ТОЛЬКО новому прогнозу и публикуем его в ленту.
        // Ничего лишнего (старым событиям картинки НЕ догенерируем — квоту не жжём).
        if (function_exists('promote_pending')){
            $p = promote_pending(2);
            if ($p) fwrite(STDERR, "[worker] promoted: $p events (image ready)\n");
        }
    }
    if ($once) break;
    sleep($paused ? 3 : 10);   // на паузе проверяем чаще, чтобы быстро возобновить
} while (true);
