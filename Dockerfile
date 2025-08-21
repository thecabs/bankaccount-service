# ---------- Build (même PHP que le runtime) ----------
FROM php:8.2-fpm-alpine AS build

# Outils + libs nécessaires à composer et aux extensions Laravel
RUN apk add --no-cache git unzip icu-dev oniguruma-dev libzip-dev $PHPIZE_DEPS \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) pdo_mysql bcmath intl zip opcache

# Installer Composer depuis l'image officielle
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app

# Étape cache-friendly
COPY composer.json composer.lock ./
# Cache du repo composer pour accélérer les builds
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-scripts --optimize-autoloader

# Code applicatif
COPY . .

# Autoloader optimisé
RUN composer dump-autoload --no-dev --optimize

# ---------- Runtime ----------
FROM php:8.2-fpm-alpine AS app

RUN apk add --no-cache icu-dev oniguruma-dev libzip-dev \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) pdo_mysql bcmath intl zip opcache

WORKDIR /var/www

# On copie tout le résultat du build
COPY --from=build /app /var/www

# Permissions minimales
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm","-F"]
