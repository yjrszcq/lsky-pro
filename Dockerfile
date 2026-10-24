# syntax=docker/dockerfile:1
FROM php:8.5.12-apache-trixie AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        libmagickwand-dev libpq-dev libzip-dev libonig-dev libicu-dev \
        unzip git fonts-dejavu-core ca-certificates \
    && docker-php-ext-install -j"$(nproc)" bcmath exif ftp intl mbstring pdo_mysql pdo_pgsql zip \
    && pecl install imagick-3.8.1 redis-6.3.0 \
    && docker-php-ext-enable imagick redis \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/lsky.ini
COPY docker/entrypoint.sh /usr/local/bin/lsky-entrypoint
RUN chmod +x /usr/local/bin/lsky-entrypoint \
    && chmod 0755 /var/www/html \
    && mkdir -p storage/app/public storage/app/uploads storage/app/thumbnails \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
ENV WEB_PORT=8089
EXPOSE 8089
ENTRYPOINT ["lsky-entrypoint"]
CMD ["apache2-foreground"]

FROM base AS dependencies
COPY composer.json composer.lock patches.lock.json ./
COPY patches ./patches
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts

FROM base AS development
ENV LSKY_DEVELOPMENT=1
ARG LOCAL_UID=1000
ARG LOCAL_GID=1000
RUN groupmod -g "$LOCAL_GID" www-data && usermod -u "$LOCAL_UID" -g "$LOCAL_GID" www-data
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
RUN chmod 0755 /var/www/html && chown -R www-data:www-data public storage bootstrap/cache

FROM node:24.19.0-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY webpack.mix.js tailwind.config.js ./
COPY resources ./resources
RUN npm run production

FROM dependencies AS production-dependencies
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader

FROM base AS production
COPY . .
COPY --from=production-dependencies /var/www/html/vendor ./vendor
COPY --from=assets /build/public ./public
RUN mkdir -p storage/app/uploads storage/app/thumbnails \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && rm -rf public/thumbnails \
    && ln -s /var/www/html/storage/app/thumbnails public/thumbnails \
    && chmod 0755 /var/www/html \
    && chown www-data:www-data public \
    && chown -R www-data:www-data storage bootstrap/cache
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r '$s = @fsockopen("127.0.0.1", (int) getenv("WEB_PORT")); if (!$s) exit(1); fwrite($s, "GET / HTTP/1.0\r\nHost: localhost\r\n\r\n"); $line = fgets($s); exit(preg_match("~HTTP/1\\.[01] [23][0-9]{2}~", $line) ? 0 : 1);'
