<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты чистых функций-помощников бэкенда (generate.php + news.php).
 * Сеть/файлы не трогаются — только детерминированная логика.
 */
final class HelpersTest extends TestCase
{
    /* ---- parse_timeframe: строка срока -> секунды ---- */
    public function testParseTimeframeUnits(): void
    {
        $this->assertSame(86400, parse_timeframe('1d'));
        $this->assertSame(3600,  parse_timeframe('1h'));
        $this->assertSame(60,    parse_timeframe('1m'));
        $this->assertSame(604800, parse_timeframe('1w'));
    }

    public function testParseTimeframeCombined(): void
    {
        // 1 час 30 минут = 5400 сек
        $this->assertSame(5400, parse_timeframe('1h30m'));
    }

    public function testParseTimeframeNumericAndFallback(): void
    {
        $this->assertSame(300, parse_timeframe('300'));   // чистое число = секунды
        $this->assertSame(3600, parse_timeframe(''));      // пусто -> дефолт 1ч
        $this->assertSame(3600, parse_timeframe('мусор')); // мусор -> дефолт 1ч
    }

    /* ---- tf_label: секунды -> человеческий текст ---- */
    public function testTfLabel(): void
    {
        $this->assertSame('30 сек', tf_label(30));
        $this->assertSame('1 дн', tf_label(86400));
        $this->assertStringContainsString('мин', tf_label(90)); // 1 мин 30 сек
    }

    /* ---- is_tf_code: валидатор кода срока ---- */
    public function testIsTfCode(): void
    {
        $this->assertTrue(is_tf_code('1d'));
        $this->assertTrue(is_tf_code('15m'));
        $this->assertFalse(is_tf_code('abc'));
        $this->assertFalse(is_tf_code('1x'));
    }

    /* ---- cat_tf_range: диапазон срока категории (min <= max, валидные коды) ---- */
    public function testCatTfRange(): void
    {
        $r = cat_tf_range('war_ru_ua');
        $this->assertArrayHasKey('min', $r);
        $this->assertArrayHasKey('max', $r);
        $this->assertTrue(is_tf_code($r['min']));
        $this->assertTrue(is_tf_code($r['max']));
        $this->assertLessThanOrEqual(parse_timeframe($r['max']), parse_timeframe($r['min']));
    }

    /* ---- category_codes: список ИИ-категорий одной строкой ---- */
    public function testCategoryCodesContainsAiCats(): void
    {
        $codes = category_codes();
        $this->assertStringContainsString('world_geo', $codes);
        $this->assertStringContainsString('putin', $codes);
        // рыночные (код-категории) в ИИ-список попадать не должны
        $this->assertStringNotContainsString('battles_global', $codes);
    }

    /* ---- human_error: техническая ошибка Gemini -> человеческий вердикт [state, msg, fatal] ---- */
    public function testHumanErrorRateLimitIsNotFatal(): void
    {
        [$state, , $fatal] = human_error(429, json_encode(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota']]));
        $this->assertSame('limit', $state);
        $this->assertFalse($fatal); // лимит модели -> пробуем следующую, не фатально
    }

    public function testHumanErrorInvalidKeyIsFatal(): void
    {
        [$state, , $fatal] = human_error(400, json_encode(['error' => ['message' => 'API key not valid']]));
        $this->assertSame('error', $state);
        $this->assertTrue($fatal); // битый ключ -> фатально, другие модели не помогут
    }

    public function testHumanErrorDepletedCreditsIsFatal(): void
    {
        [$state, , $fatal] = human_error(429, json_encode(['error' => ['message' => 'prepayment credits are depleted']]));
        $this->assertTrue($fatal); // платный проект с нулём -> ключ фатально пропускается
    }

    /* ---- asset_display: тикер -> читаемое имя (или сам тикер, если неизвестен) ---- */
    public function testAssetDisplayFallback(): void
    {
        $this->assertSame('ZZZUNKNOWN', asset_display('ZZZUNKNOWN'));
    }

    /* ---- img_badge: генерит SVG-бейдж как data-URI ---- */
    public function testImgBadgeIsSvgDataUri(): void
    {
        $b = img_badge('Тест');
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $b);
    }

    /* ---- company_logo_for: не должен ложно срабатывать на бессмысленном тексте ---- */
    public function testCompanyLogoForNoFalsePositive(): void
    {
        $this->assertNull(company_logo_for('просто какой-то текст без компаний zzzz'));
    }

    /* ---- company_logo_for: бренды подставляем, страны/концепты — НЕТ (для них генерится картинка) ---- */
    public function testCompanyLogoBrandYesCountryNo(): void
    {
        // бренд (есть в $stems) -> подставляем готовый логотип
        $this->assertNotNull(company_logo_for('Станет ли Apple дороже к 2027?'));
        // страна -> НЕ подставляем лого, событие сгенерит свою картинку-сцену
        $this->assertNull(company_logo_for('Что решит Китай по Тайваню?'));
    }

    /* ---- закрытый вопрос: строго ДА/НЕТ, лишний третий вариант отбрасывается ---- */
    public function testYesNoWithExtraOptionBecomesClosedTwoOptions(): void
    {
        $e = build_event_from_ai(
            ['type' => 'open', 'options' => ['Да', 'Нет', 'Возможно'], 'category' => 'putin', 'question' => 'Случится ли?'],
            []
        );
        $this->assertSame('closed', $e['type']);
        $this->assertCount(2, $e['options']);           // ровно два, третий убран
        $this->assertSame('ДА', $e['options'][0]['label']);
        $this->assertSame('НЕТ', $e['options'][1]['label']);
    }

    /* ---- открытый вопрос: у каждого варианта своя сцена (image_en), а не общий бейдж ---- */
    public function testOpenOptionsGetPerOptionImageEn(): void
    {
        $e = build_event_from_ai(
            [
                'type' => 'open',
                'options' => ['Партия А', 'Партия Б'],
                'options_en' => ['party A leader at a rally', 'party B flag at a congress'],
                'category' => 'ru_internal',
                'question' => 'Кто победит на выборах?',
            ],
            []
        );
        $this->assertSame('open', $e['type']);
        $this->assertCount(2, $e['options']);
        $this->assertSame('party A leader at a rally', $e['options'][0]['image_en']);
        $this->assertArrayNotHasKey('image', $e['options'][0]); // есть сцена -> бейдж не ставится
    }

    /* ---- is_numeric_answer: число/диапазон vs смысловой ответ ---- */
    public function testIsNumericAnswer(): void
    {
        $this->assertTrue(is_numeric_answer('выше 100 ₽'));
        $this->assertTrue(is_numeric_answer('90–95'));
        $this->assertTrue(is_numeric_answer('менее 30%'));
        $this->assertTrue(is_numeric_answer('ниже 90 ₽'));
        $this->assertFalse(is_numeric_answer('Да, останется у поста'));
        $this->assertFalse(is_numeric_answer('Партия А'));
        $this->assertFalse(is_numeric_answer('Нет, уволят'));
    }

    /* ---- числовые варианты открытого вопроса НЕ получают картинку (только текст) ---- */
    public function testNumericOptionsGetNoImage(): void
    {
        $e = build_event_from_ai(
            [
                'type' => 'open',
                'options' => ['ниже 90', '90–95', 'выше 95'],
                'options_en' => ['scene x', 'scene y', 'scene z'], // даже если модель прислала — для чисел игнорим
                'category' => 'ru_econ',
                'question' => 'Каким будет курс доллара?',
            ],
            []
        );
        $this->assertSame('open', $e['type']);
        foreach ($e['options'] as $o){
            $this->assertArrayNotHasKey('image_en', $o); // числовой -> без сцены
            $this->assertArrayNotHasKey('image', $o);    // и без бейджа-картинки, только текст
        }
    }

    /* ---- единообразие: если есть хоть один смысловой ответ — картинка у ВСЕХ вариантов ---- */
    public function testMixedOptionsAllGetImages(): void
    {
        $e = build_event_from_ai(
            [
                'type' => 'open',
                'options' => ['Да, останется', 'выше 100'],   // один смысловой, один числовой
                'options_en' => ['premier at podium', ''],     // у числового сцены нет
                'category' => 'world_geo',
                'question' => 'Останется ли премьер?',
            ],
            []
        );
        // не все числовые -> у КАЖДОГО варианта должна быть картинка (сцена или бейдж), без «один с, другой без»
        foreach ($e['options'] as $o){
            $this->assertTrue(isset($o['image_en']) || isset($o['image']), 'у варианта нет картинки — нарушено единообразие');
        }
    }
}
