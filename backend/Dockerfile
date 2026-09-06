FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts

FROM php:8.4-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq5 libzip4 libpq-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql zip bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove libpq-dev libzip-dev $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN php artisan package:discover --ansi
EXPOSE 8000
CMD ["sh", "-c", "php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000"]
