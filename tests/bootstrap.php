<?php
/**
 * Тестовый bootstrap.
 * Подключаем бэкенд как БИБЛИОТЕКУ (без запуска HTTP-роутеров):
 *   GEN_INCLUDE  — generate.php не запускает свой роутер
 *   NEWS_INCLUDE — news.php  не запускает свой роутер
 * После подключения все функции доступны для юнит-тестов.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

define('GEN_INCLUDE', true);
define('NEWS_INCLUDE', true);

// PHPUnit грузит bootstrap из метода — объявляем нужные переменные глобальными,
// чтобы присваивания в подключаемых файлах (generate.php: $CATS и т.п.) попали в глобальную область.
global $CATS, $CAT_LABEL, $ASSET_NAME, $TF_HINTS, $PRESETS, $DATA, $CONFIG, $NEWS;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../generator/news.php';   // тянет и generate.php (jload, parse_timeframe, $CATS, ...)
require_once __DIR__ . '/../generator/imglib.php'; // провайдеры картинок + WebP
