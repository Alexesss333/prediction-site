<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты слоя генерации картинок (imglib.php):
 * нормализация ключей, цепочка фолбэка между провайдерами, конвертация в WebP.
 * Сеть не трогается — только логика выбора и обработка байтов.
 */
final class ImageProviderTest extends TestCase
{
    /* ---- img_key_tail: хвост ключа (для статуса/подсветки) ---- */
    public function testKeyTailFromString(): void
    {
        $this->assertSame('123456', img_key_tail('ABCDEF123456'));
    }

    public function testKeyTailFromCloudflarePair(): void
    {
        $this->assertSame('xyz789', img_key_tail(['account' => 'acc', 'token' => 'tok-xyz789']));
    }

    /* ---- img_provider_keys: нормализация списка ключей провайдера ---- */
    public function testProviderKeysTogether(): void
    {
        $keys = img_provider_keys('together', ['together_keys' => ['k1', 'k2']]);
        $this->assertSame(['k1', 'k2'], $keys);
    }

    public function testProviderKeysCloudflarePairs(): void
    {
        $keys = img_provider_keys('cloudflare', ['cf_keys' => [
            ['account' => 'a1', 'token' => 't1'],
            ['account' => 'a2', 'token' => 't2'],
        ]]);
        $this->assertCount(2, $keys);
        $this->assertSame('t1', $keys[0]['token']);
    }

    public function testProviderKeysCloudflareSkipsEntriesWithoutToken(): void
    {
        $keys = img_provider_keys('cloudflare', ['cf_keys' => [
            ['account' => 'a1'],                       // нет token -> пропустить
            ['account' => 'a2', 'token' => 't2'],
        ]]);
        $this->assertCount(1, $keys);
        $this->assertSame('t2', $keys[0]['token']);
    }

    /* ---- img_provider_chain: порядок авто-фолбэка между сервисами ---- */
    public function testChainActiveFirstPollinationsLast(): void
    {
        $chain = img_provider_chain([
            'img_provider' => 'cloudflare',
            'cf_keys' => [['account' => 'a', 'token' => 't']],
        ]);
        $this->assertSame('cloudflare', $chain[0]);          // активный — первым
        $this->assertSame('pollinations', end($chain));      // Pollinations — крайний запасной
        $this->assertSame(array_unique($chain), $chain);     // без дублей
    }

    public function testChainFallsFromDeadServiceToNext(): void
    {
        // активный together (в этом cfg без ключей), но у cloudflare ключи есть -> он идёт в цепочку
        $chain = img_provider_chain([
            'img_provider' => 'together',
            'cf_keys' => [['account' => 'a', 'token' => 't']],
        ]);
        $this->assertSame('together', $chain[0]);
        $this->assertContains('cloudflare', $chain);
        $this->assertSame('pollinations', end($chain));
    }

    public function testChainPollinationsOnlyWhenNoKeys(): void
    {
        $chain = img_provider_chain(['img_provider' => 'pollinations']);
        $this->assertSame(['pollinations'], $chain);
    }

    /* ---- img_to_webp: сырые байты картинки -> WebP (нужен GD с webp, он есть в образе) ---- */
    public function testImgToWebpConvertsPng(): void
    {
        if (!function_exists('imagewebp') || !function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD/WebP недоступен в этой среде');
        }
        // делаем PNG в памяти (64x64 с фигурами, чтобы WebP получился заметного размера, не «мусорным»)
        $im = imagecreatetruecolor(64, 64);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
        imagefilledrectangle($im, 5, 5, 40, 40, imagecolorallocate($im, 200, 60, 60));
        imagefilledellipse($im, 40, 40, 30, 30, imagecolorallocate($im, 60, 200, 120));
        ob_start(); imagepng($im); $png = ob_get_clean();
        imagedestroy($im);

        $webp = img_to_webp($png);
        $this->assertNotFalse($webp);
        $this->assertNotEmpty($webp);
        // WebP-файл начинается с "RIFF"...."WEBP"
        $this->assertSame('RIFF', substr($webp, 0, 4));
        $this->assertSame('WEBP', substr($webp, 8, 4));
    }

    public function testImgToWebpRejectsGarbage(): void
    {
        $this->assertFalse(img_to_webp('это не картинка, а мусор'));
    }
}
