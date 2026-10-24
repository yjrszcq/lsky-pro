#!/bin/sh
set -eu

# One-off Artisan commands must read the same persisted configuration as Apache.
if [ -f storage/.env ]; then
    ln -sfn /var/www/html/storage/.env .env
    ln -sfn /var/www/html/storage/installed.lock installed.lock
fi

if [ "${1:-}" = 'apache2-foreground' ]; then
    case "$WEB_PORT" in
        ''|*[!0-9]*) echo 'WEB_PORT must be an integer from 1 to 65535' >&2; exit 1 ;;
    esac
    if [ "$WEB_PORT" -lt 1 ] || [ "$WEB_PORT" -gt 65535 ]; then
        echo 'WEB_PORT must be an integer from 1 to 65535' >&2
        exit 1
    fi
    printf 'Listen %s\n' "$WEB_PORT" > /etc/apache2/ports.conf
    mkdir -p storage/app/uploads storage/app/thumbnails \
        storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

    # Persist install state and encryption key alongside uploaded data.
    if [ ! -f storage/.env ]; then
        if [ -f .env ] && [ ! -L .env ]; then
            cp .env storage/.env
        else
            cp .env.example storage/.env
        fi
    fi
    if [ -f installed.lock ] && [ ! -L installed.lock ]; then
        mv installed.lock storage/installed.lock
    fi
    ln -sfn /var/www/html/storage/.env .env
    ln -sfn /var/www/html/storage/installed.lock installed.lock
    if [ "${LSKY_DEVELOPMENT:-0}" != 1 ] && [ ! -L public/thumbnails ]; then
        cp -a public/thumbnails/. storage/app/thumbnails/
        rm -rf public/thumbnails
        ln -s /var/www/html/storage/app/thumbnails public/thumbnails
    fi
    ln -sfn /var/www/html/storage/app/uploads public/i
    chown -R www-data:www-data storage bootstrap/cache
    if ! grep -q '^APP_KEY=base64:' storage/.env; then
        php artisan key:generate --force
    fi
    php artisan package:discover --ansi
    if [ -f installed.lock ]; then
        php artisan lsky:storage-link
    fi
fi

exec docker-php-entrypoint "$@"
