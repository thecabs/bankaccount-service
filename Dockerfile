# -------- Vendors (prod) --------
FROM composer:2.7 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction
COPY . .
RUN composer dump-autoload --optimize

# -------- Runtime PHP-FPM --------
FROM php:8.2-fpm-alpine AS app
WORKDIR /var/www

# Extensions Laravel usuelles
RUN apk add --no-cache icu-dev oniguruma-dev libzip-dev \
 && docker-php-ext-install pdo_mysql bcmath intl opcache

# Code + vendors
COPY --from=vendor /app /var/www

# Permissions minimalistes
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm","-F"]
