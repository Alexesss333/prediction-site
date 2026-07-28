#!/bin/sh
# Прогон тестов (PHPUnit) внутри Docker-контейнера.
# Собирает образ, ставит зависимости в примонтированную папку и запускает тесты.
# Использование:  ./run-tests.sh                — все тесты
#                 ./run-tests.sh --filter Name  — доп. флаги PHPUnit пробрасываются
set -e

echo "==> Сборка образа (если нужно)..."
docker compose build

echo "==> Установка зависимостей + запуск PHPUnit..."
docker compose run --rm --no-deps web sh -c \
  "composer install --no-interaction --no-progress && php vendor/bin/phpunit --colors=always $*"
