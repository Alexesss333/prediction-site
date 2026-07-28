# Self-contained runtime: PHP web server + background auto-gen worker.
FROM php:8.2-cli

WORKDIR /app

# GD with WebP/JPEG/PNG so the script can re-encode generated images to .webp
RUN apt-get update \
    && apt-get install -y --no-install-recommends libwebp-dev libjpeg-dev libpng-dev libfreetype6-dev unzip git \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd \
    && rm -rf /var/lib/apt/lists/*

# Composer (для установки dev-зависимостей: PHPUnit)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . /app

# зависимости (PHPUnit для тестов); не падаем, если сети нет при сборке
RUN composer update --no-interaction --no-progress --optimize-autoloader || echo "composer step skipped"

# make the data folder writable for the generator
RUN mkdir -p /app/data && chmod -R 777 /app/data && chmod +x /app/start.sh

EXPOSE 8080
# start.sh runs the worker (auto-generation) in background + php built-in server
CMD ["sh", "/app/start.sh"]
