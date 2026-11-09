#!/bin/sh
set -eu
umask 077
cd "$(dirname "$0")/../.."

app_uid=10001
app_gid=10001

if [ "$#" -eq 0 ]; then
    set -- web
fi

role=$1
shift

# Only the one-off administrative roles may start as root, and only to hand
# the mounted installation directories to the application user. Everything
# else, including the application commands of those roles, runs as 10001.
if [ "$(id -u)" = 0 ]; then
    case "$role" in
        setup|restore) ;;
        *)
            echo "The $role role must not run as root." >&2
            exit 1
            ;;
    esac

    for directory in /app/storage /keys /data /backups; do
        if [ -d "$directory" ]; then
            chown "$app_uid:$app_gid" "$directory"
            chmod 700 "$directory"
        fi
    done
    # Key files may have been copied in from a key backup by another user.
    if [ -d /keys ]; then
        chown -R -P "$app_uid:$app_gid" /keys
        find -P /keys -type f -exec chmod 0600 {} +
    fi

    if [ "$role" = restore ]; then
        set_path=$(realpath -e -- "${1:-}" 2>/dev/null || true)
        if [ -z "$set_path" ] || [ ! -d "$set_path" ] || [ "$(dirname "$set_path")" != /backups ]; then
            echo "Put the backup set into the backups/ folder and pass /backups/BACKUP_ID." >&2
            exit 64
        fi
        chown -R -P "$app_uid:$app_gid" "$set_path"
    fi

    exec setpriv --reuid="$app_uid" --regid="$app_gid" --clear-groups -- "$0" "$role" "$@"
fi

mkdir -p storage/app/private/tmp storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# These caches belong to this container, never to a shared persistent mount.
export APP_CONFIG_CACHE="$PWD/bootstrap/cache/config.php"
rm -f "$APP_CONFIG_CACHE"

# Setup creates key material and restore replaces the database, so they read
# configuration fresh in every step instead of caching it first.
case "$role" in
    setup)
        exec php artisan vaultigen:setup "$@"
        ;;
    restore)
        if [ "$#" -lt 1 ]; then
            echo "Usage: restore /backups/BACKUP_ID [--force]" >&2
            exit 64
        fi
        set_path=$1
        shift
        php artisan down --no-interaction
        php artisan vaultigen:restore "$set_path" --services-stopped "$@"
        exec php artisan up --no-interaction
        ;;
esac

# Standard output belongs to the role, for example JSON piped to the host.
php artisan config:cache --no-interaction >&2

case "$role" in
    web)
        exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile "$@"
        ;;
    migrate)
        exec php artisan vaultigen:migrate --no-interaction "$@"
        ;;
    worker)
        exec php artisan queue:work --sleep=3 --tries=1 --timeout=3600 "$@"
        ;;
    scheduler)
        exec php artisan schedule:work --no-interaction "$@"
        ;;
    artisan)
        exec php artisan "$@"
        ;;
    *)
        exec "$role" "$@"
        ;;
esac
