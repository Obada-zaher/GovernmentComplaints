FROM php:8.3-cli

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        ca-certificates \
        libzip-dev \
        libpng-dev \
        libonig-dev \
        libpq-dev \
        default-mysql-client \
    && docker-php-ext-install pdo_mysql pdo_pgsql mbstring zip gd bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN mkdir -p \
        resources/views \
        storage/framework/views \
        storage/framework/cache \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/logs \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache resources/views || true

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

RUN chmod -R 775 storage bootstrap/cache || true

EXPOSE 10000

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]

CMD sh -c "php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"
