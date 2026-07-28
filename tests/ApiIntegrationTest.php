<?php

use PHPUnit\Framework\TestCase;

/**
 * Интеграционные тесты HTTP-эндпоинтов.
 * Поднимают ОТДЕЛЬНЫЙ php-сервер со СВОЕЙ временной папкой данных (PS_DATA_DIR),
 * поэтому реальные data/ и ключи НЕ трогаются. Внешние API (Gemini/Cloudflare) не вызываются —
 * проверяем маршрутизацию, JSON, конфиг и управление ключами.
 */
final class ApiIntegrationTest extends TestCase
{
    private static $proc;
    private static array $pipes = [];
    private static string $base = '';
    private static string $data = '';

    public static function setUpBeforeClass(): void
    {
        self::$data = sys_get_temp_dir() . '/ps_it_' . getmypid();
        @mkdir(self::$data, 0777, true);
        @mkdir(self::$data . '/genimg', 0777, true);
        // сид: минимальный конфиг, авто-поток выключен (чтобы ничего не генерилось в фоне)
        file_put_contents(self::$data . '/config.json', json_encode(['auto' => ['active' => false]]));

        $root = dirname(__DIR__);
        $port = 8390;
        self::$base = "http://127.0.0.1:$port";

        $env = getenv();                       // наследуем окружение (PATH и т.п.)
        $env['PS_DATA_DIR'] = self::$data;     // + изолированная папка данных

        $descr = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        self::$proc = proc_open(
            ['php', '-d', 'error_reporting=0', '-S', "127.0.0.1:$port", '-t', $root],
            $descr, self::$pipes, $root, $env
        );
        if (!is_resource(self::$proc)) {
            self::fail('Не удалось запустить php -S для интеграционных тестов');
        }
        // ждём, пока сервер поднимется
        $up = false;
        for ($i = 0; $i < 50; $i++) {
            $r = @file_get_contents(self::$base . '/generator/generate.php?action=list');
            if ($r !== false) { $up = true; break; }
            usleep(200000); // 0.2s
        }
        if (!$up) self::fail('Тестовый php-сервер не поднялся за 10 секунд');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            foreach (self::$pipes as $p) { if (is_resource($p)) fclose($p); }
            proc_close(self::$proc);
        }
        // чистим временную папку
        $d = self::$data;
        if ($d && is_dir($d)) {
            foreach (glob($d . '/*') as $f) { is_dir($f) ? @rmdir($f) : @unlink($f); }
            @rmdir($d);
        }
    }

    /** GET эндпоинта -> распарсенный JSON */
    private function get(string $path): array
    {
        $raw = file_get_contents(self::$base . $path);
        $this->assertNotFalse($raw, "запрос упал: $path");
        $j = json_decode($raw, true);
        $this->assertIsArray($j, "ответ не JSON: $path -> $raw");
        return $j;
    }

    /* ---- лента событий ---- */
    public function testListReturnsEventsArray(): void
    {
        $j = $this->get('/generator/generate.php?action=list');
        $this->assertArrayHasKey('events', $j);
        $this->assertIsArray($j['events']);
    }

    /* ---- meta: категории для админки ---- */
    public function testMetaReturnsCategories(): void
    {
        $j = $this->get('/generator/generate.php?action=meta');
        $this->assertTrue($j['ok'] ?? false);
        $this->assertNotEmpty($j['categories']);
    }

    /* ---- конфиг: дефолты на месте ---- */
    public function testConfigGetHasDefaults(): void
    {
        $j = $this->get('/generator/news.php?action=config_get');
        $c = $j['config'] ?? [];
        $this->assertArrayHasKey('img_provider', $c);
        $this->assertArrayHasKey('gemini_models', $c);
        $this->assertArrayHasKey('cf_keys', $c);
    }

    /* ---- список новостей ---- */
    public function testNewsListReturnsArray(): void
    {
        $j = $this->get('/generator/news.php?action=news_list');
        $this->assertTrue($j['ok'] ?? false);
        $this->assertIsArray($j['news']);
    }

    /* ---- config_save: round-trip записи и чтения ---- */
    public function testConfigSaveRoundTrip(): void
    {
        $save = $this->get('/generator/news.php?action=config_save&feed_per_cat=7');
        $this->assertSame(7, (int)($save['config']['feed_per_cat'] ?? 0));
        // перечитали свежим запросом — значение сохранилось
        $get = $this->get('/generator/news.php?action=config_get');
        $this->assertSame(7, (int)($get['config']['feed_per_cat'] ?? 0));
    }

    /* ---- управление ключами картинок: добавление и удаление (то самое «что-то с ключами») ---- */
    public function testImageKeyAddAndDelete(): void
    {
        // старт — ключей нет
        $c0 = $this->get('/generator/news.php?action=config_get')['config'];
        $this->assertCount(0, $c0['cf_keys'] ?? []);

        // добавили Cloudflare-ключ
        $add = $this->get('/generator/news.php?action=img_key_add&provider=cloudflare&account=acc123&key=tok999');
        $this->assertTrue($add['ok'] ?? false);
        $this->assertCount(1, $add['config']['cf_keys']);
        $this->assertSame('acc123', $add['config']['cf_keys'][0]['account']);

        // удалили
        $del = $this->get('/generator/news.php?action=img_key_del&provider=cloudflare&idx=0');
        $this->assertTrue($del['ok'] ?? false);
        $this->assertCount(0, $del['config']['cf_keys']);
    }

    /* ---- провайдер без ключей -> аккуратная ошибка 502 (без падения, без внешних вызовов) ---- */
    public function testImageProviderWithoutKeysFailsGracefully(): void
    {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
        $url = self::$base . '/generator/img.php?provider=segmind&w=64&seed=1&q=' . rawurlencode('test');
        file_get_contents($url, false, $ctx);
        // $http_response_header заполняется после запроса
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
        }
        $this->assertSame(502, $status); // нет ключей segmind -> 502, а не падение
    }
}
