# -------- Stage 1 : Composer vendors (prod) --------
FROM composer:2.7 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress
COPY . .
# Autoloader optimisé
RUN composer dump-autoload --optimize

# -------- Stage 2 : PHP-FPM runtime --------
FROM php:8.2-fpm-alpine AS app
WORKDIR /var/www

# Extensions de base pour Laravel
RUN docker-php-ext-install pdo pdo_mysql bcmath && docker-php-ext-enable opcache

# Copie du code + vendors
COPY --from=vendor /app /var/www

# Droits minimaux (écriture sur storage/cache)
RUN chown -R www-data:www-data storage bootstrap/cache

# Port FPM
EXPOSE 9000
CMD ["php-fpm", "-F"]

# -------- Stage 3 : Nginx (reverse proxy) --------
FROM nginx:1.27-alpine AS web
WORKDIR /var/www
# On ne re-copie pas le code ici : on montera un volume partagé (emptyDir) au runtime K8s
# mais pour build "tout-en-un" local tu peux décommenter :
# COPY --from=vendor /app /var/www

# Conf Nginx (at fournie par ConfigMap en prod)
COPY ./deploy/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 8080
CMD ["nginx", "-g", "daemon off;"]
