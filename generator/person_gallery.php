<?php
/* Набор настоящих фотографий человека — чтобы листать и выбирать вручную.

   Одно фото из карточки статьи часто не годится: там парадный портрет, а нужен
   то профиль, то фигура целиком. Викисклад по запросу отдаёт десятки снимков
   одного человека, все со свободной лицензией.

   Ищем именно на Викискладе, а не в поисковике: там лицензия позволяет
   использование, а выдача поисковика — чужие права. */

require_once __DIR__ . '/person_photo.php';

const GALLERY_DIR = __DIR__ . '/../data/persons';
const GALLERY_MAX = 30;

/* Латинское имя для поиска: русские запросы Викисклад почти не находит. */
const PERSON_SEARCH = [
    'Орбан'         => 'Viktor Orban',
    'Мадьяр'        => 'Peter Magyar',
    'Путин'         => 'Vladimir Putin',
    'Зеленск'       => 'Volodymyr Zelenskyy',
    'Трамп'         => 'Donald Trump',
    'Макрон'        => 'Emmanuel Macron',
    'Мерц'          => 'Friedrich Merz',
    'Мелони'        => 'Giorgia Meloni',
    'Си Цзиньпин'   => 'Xi Jinping',
    'Лукашенко'     => 'Alexander Lukashenko',
    'Эрдоган'       => 'Recep Tayyip Erdogan',
    'Шольц'         => 'Olaf Scholz',
    'Байден'        => 'Joe Biden',
    'Стармер'       => 'Keir Starmer',
    'Туск'          => 'Donald Tusk',
    'Набиуллина'    => 'Elvira Nabiullina',
    'Мишустин'      => 'Mikhail Mishustin',
    'Лавров'        => 'Sergey Lavrov',
    'Пауэлл'        => 'Jerome Powell',
    'Санчес'        => 'Pedro Sanchez',
    'Лекорню'       => 'Sebastien Lecornu',
    'Нордио'        => 'Carlo Nordio',
    'Сальвини'      => 'Matteo Salvini',
    'Ляйен'         => 'Ursula von der Leyen',
    'Костин'        => 'Andrey Kostin',
    'Фицо'          => 'Robert Fico',
    'Вучич'         => 'Aleksandar Vucic',
    'Навроцкий'     => 'Karol Nawrocki',
    'Каллас'        => 'Kaja Kallas',
    'Рютте'         => 'Mark Rutte',
];

/** Кто назван в тексте: подпись => латинское имя для поиска. */
function gallery_detect(string $text): array {
    $found = [];
    foreach (PERSON_SEARCH as $needle => $latin) {
        if (mb_stripos($text, $needle) !== false) $found[$needle] = $latin;
    }
    return $found;
}

function gallery_api(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'prediction-site/1.0 (admin tool)',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body ? (json_decode($body, true) ?: null) : null;
}

/** Список адресов фотографий человека на Викискладе. */
function gallery_search(string $latin): array {
    $url = 'https://commons.wikimedia.org/w/api.php?action=query&format=json'
         . '&generator=search&gsrnamespace=6&gsrlimit=' . GALLERY_MAX
         . '&gsrsearch=' . rawurlencode($latin . ' portrait')
         . '&prop=imageinfo&iiprop=url|mime&iiurlwidth=400';

    $j = gallery_api($url);
    $pages = $j['query']['pages'] ?? [];

    $out = [];
    foreach ($pages as $p) {
        $ii = $p['imageinfo'][0] ?? null;
        if (!$ii) continue;
        // Викисклад держит в тех же категориях PDF и схемы — берём только снимки.
        if (strpos($ii['mime'] ?? '', 'image/') !== 0) continue;
        if (preg_match('~\.(svg|gif)$~i', $p['title'] ?? '')) continue;
        $src = $ii['thumburl'] ?? $ii['url'] ?? null;
        if ($src) $out[] = $src;
    }
    return $out;
}

/** Скачивает фото в webp и отдаёт адрес внутри сайта (или null). */
function gallery_fetch(string $src, string $latin, int $idx): ?string {
    if (!is_dir(GALLERY_DIR)) @mkdir(GALLERY_DIR, 0777, true);

    $slug = substr(md5($latin), 0, 10) . '_' . $idx;
    $file = GALLERY_DIR . '/' . $slug . '.webp';
    $url  = 'data/persons/' . $slug . '.webp';
    if (is_file($file) && filesize($file) > 0) return $url;

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

/** Галерея для текста вопроса или варианта: [ [name, photos[]], ... ] */
function gallery_for(string $text): array {
    $out = [];
    foreach (gallery_detect($text) as $label => $latin) {
        $photos = [];
        foreach (gallery_search($latin) as $i => $src) {
            $p = gallery_fetch($src, $latin, $i);
            if ($p) $photos[] = $p;
        }
        if ($photos) $out[] = ['name' => $label, 'latin' => $latin, 'photos' => $photos];
    }
    return $out;
}
