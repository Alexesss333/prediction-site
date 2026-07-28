#!/bin/sh
# Start the background auto-generation worker + the web server in one container.
php /app/generator/worker.php &
# Многопроцессный встроенный сервер: иначе `php -S` одно-поточный и отдаёт картинки
# строго по очереди — на странице ~100 картинок «капают» по одной. Воркеры отдают их
# параллельно (браузер держит до 6 соединений). Запись json атомарна (jsave) — гонок нет.
export PHP_CLI_SERVER_WORKERS=8
exec php -S 0.0.0.0:8080 -t /app
