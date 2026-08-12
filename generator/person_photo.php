<?php
/* Настоящее фото человека вместо нарисованного лица.
   Модели-рисовалки (flux-schnell и подобные) знают публичных людей выборочно:
   Путина узнают, Орбана — нет, и в кадр попадает посторонний. Для вопросов,
   где фамилия названа прямо, берём портрет из Википедии: он всегда тот самый.

   Источник — открытый API Википедии: ключей не нужно, лицензия свободная. */

const PERSON_DIR = __DIR__ . '/../data/persons';

/* Кого узнаём в вопросе. Ключ — как пишут в тексте (по нему ищем вхождение),
   значение — заголовок статьи в русской Википедии.
   Список расширяется по мере появления новых имён в пачках. */
const PERSON_MAP = [
    'Орбан'        => 'Орбан, Виктор',
    'Мадьяр'       => 'Мадьяр, Петер',
    'Путин'        => 'Путин, Владимир Владимирович',
    'Зеленск'      => 'Зеленский, Владимир Александрович',
    'Трамп'        => 'Трамп, Дональд',
    'Макрон'       => 'Макрон, Эмманюэль',
    'Мерц'         => 'Мерц, Фридрих',
    'Мелони'       => 'Мелони, Джорджа',
    'Си Цзиньпин'  => 'Си Цзиньпин',
    'Лукашенко'    => 'Лукашенко, Александр Григорьевич',
    'Эрдоган'      => 'Эрдоган, Реджеп Тайип',
    'Шольц'        => 'Шольц, Олаф',
    'Байден'       => 'Байден, Джо',
    'Стармер'      => 'Стармер, Кир',
    'Туск'         => 'Туск, Дональд',
    'Набиуллина'   => 'Набиуллина, Эльвира Сахипзадовна',
    'Мишустин'     => 'Мишустин, Михаил Владимирович',
    'Лавров'       => 'Лавров, Сергей Викторович',
    'Пауэлл'       => 'Пауэлл, Джером',
    // Полное имя: по короткому «Санчес, Педро» Википедия отдаёт статью-неоднозначность без фото.
    'Санчес'       => 'Санчес Перес-Кастехон, Педро',
    'Лекорню'      => 'Лекорню, Себастьен',
    'Нордио'       => 'Нордио, Карло',
    'Сальвини'     => 'Сальвини, Маттео',
    'Ляйен'        => 'Ляйен, Урсула фон дер',
    'фон дер Ляйен'=> 'Ляйен, Урсула фон дер',
    'Костин'       => 'Костин, Андрей Леонидович',
];

/** Кто упомянут в тексте вопроса. Возвращает [подпись => статья] или []. */
function person_detect(string $text): array {
    $found = [];
    foreach (PERSON_MAP as $needle => $article) {
        if (mb_stripos($text, $needle) !== false) $found[$needle] = $article;
    }
    return $found;
}

/** Скачивает портрет и возвращает путь к локальному файлу (или null). */
function person_photo(string $article): ?string {
    if (!is_dir(PERSON_DIR)) @mkdir(PERSON_DIR, 0777, true);

    $slug = substr(md5($article), 0, 16);
    $file = PERSON_DIR . '/' . $slug . '.webp';
    $url  = 'data/persons/' . $slug . '.webp';
    if (is_file($file) && filesize($file) > 0) return $url;      // уже скачан

    $api = 'https://ru.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($article);
    $ch = curl_init($api);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        // Википедия отклоняет запросы без внятного User-Agent.
        CURLOPT_USERAGENT      => 'prediction-site/1.0 (admin tool)',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return null;

    $j = json_decode($body, true);
    // Берём thumbnail, а не originalimage: полноразмерные портреты бывают по
    // 50+ МБ, и преобразование в webp упирается в лимит памяти PHP (128 МБ).
    // Для миниатюры на странице разрешения thumbnail с запасом хватает.
    $src = $j['thumbnail']['source'] ?? $j['originalimage']['source'] ?? null;
    if (!$src) return null;
    // Размер в ссылке не подменяем: Wikimedia отдаёт 400 на произвольную ширину,
    // если такой вариант не сгенерирован. Штатного thumbnail (обычно 320-330 px)
    // для миниатюры на странице достаточно.

    $ch = curl_init($src);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'prediction-site/1.0 (admin tool)',
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!$raw) return null;

    require_once __DIR__ . '/imglib.php';
    $wp = img_to_webp($raw);
    if ($wp === false) return null;

    file_put_contents($file, $wp);
    return $url;
}

/** Портрет для текста вопроса/варианта: ищет имя и отдаёт файл. */
function person_photo_for(string $text): ?string {
    foreach (person_detect($text) as $article) {
        $p = person_photo($article);
        if ($p) return $p;
    }
    return null;
}
