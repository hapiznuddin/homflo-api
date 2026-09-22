FROM dunglas/frankenphp:php8.3-bookworm

WORKDIR /app

RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    bcmath \
    pcntl \
    opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install \
    --no-interaction \
    --prefer-dist \
    --no-progress

RUN mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

RUN chown -R www-data:www-data \
    storage \
    bootstrap/cache

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/app/Caddyfile"]
