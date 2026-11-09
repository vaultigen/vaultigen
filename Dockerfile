# syntax=docker/dockerfile:1
ARG FRANKENPHP_IMAGE=docker.io/dunglas/frankenphp:1.13.3-php8.5-trixie
ARG MYSQL_IMAGE=docker.io/library/mysql:8.4

# Keep Oracle's MySQL clients separate from Debian's MariaDB clients.
FROM ${MYSQL_IMAGE} AS mysql-client
USER root
RUN set -eux; \
    mkdir -p /client/bin /client/lib; \
    cp /usr/bin/mysql /usr/bin/mysqldump /client/bin/; \
    ldd /usr/bin/mysql /usr/bin/mysqldump \
        | awk '/=> \// { print $3 }' \
        | sort -u \
        | while read -r library; do \
            case "$(basename "$library")" in \
                libc.so.*|libm.so.*|libpthread.so.*|libdl.so.*|librt.so.*) ;; \
                *) cp -L "$library" /client/lib/ ;; \
            esac; \
        done; \
    if [ -d /usr/lib64/mysql/plugin ]; then cp -a /usr/lib64/mysql/plugin /client/lib/; fi; \
    cp -a /usr/share/licenses /client/licenses

FROM ${FRANKENPHP_IMAGE} AS php-runtime
USER root
RUN set -eux; \
    install-php-extensions bcmath ctype curl dom exif fileinfo gd intl mbstring \
        pcntl pdo_mysql pdo_pgsql pdo_sqlite simplexml sodium tokenizer xml xmlreader xmlwriter zip; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gnupg mariadb-client tini; \
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        | gpg --dearmor -o /usr/share/keyrings/postgresql.gpg; \
    echo 'deb [signed-by=/usr/share/keyrings/postgresql.gpg] https://apt.postgresql.org/pub/repos/apt trixie-pgdg main' \
        > /etc/apt/sources.list.d/postgresql.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends postgresql-client-18; \
    apt-get purge -y --auto-remove gnupg $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*; \
    rm -rf /usr/local/include/php /usr/local/lib/php/build /usr/src/php*; \
    rm -f /usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/phpdbg \
        /usr/local/bin/pecl /usr/local/bin/pear /usr/local/bin/peardev \
        /usr/local/bin/install-php-extensions /usr/local/bin/docker-php-*; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    groupadd --gid 10001 vaultigen; \
    useradd --uid 10001 --gid 10001 --no-create-home --home-dir /app/storage --shell /usr/sbin/nologin vaultigen; \
    setcap -r /usr/local/bin/frankenphp
COPY --from=mysql-client /client /opt/mysql
COPY scripts/docker/mysql-client.sh /usr/local/bin/mysql
COPY scripts/docker/mysql-client.sh /usr/local/bin/mysqldump
RUN chmod 755 /usr/local/bin/mysql /usr/local/bin/mysqldump \
    && mysql --version && mysqldump --version && mariadb --version && mariadb-dump --version \
    && /usr/lib/postgresql/18/bin/pg_dump --version && /usr/lib/postgresql/18/bin/psql --version
ENV PATH="/usr/lib/postgresql/18/bin:${PATH}"
WORKDIR /app

FROM docker.io/library/composer:2 AS composer-binary

FROM php-runtime AS dependencies
COPY --from=composer-binary /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database/migrations ./database/migrations
COPY lang ./lang
COPY resources ./resources
COPY routes ./routes
COPY public ./public
COPY artisan LICENSE ./
COPY docs/legal ./docs/legal
RUN set -eux; \
    rm -rf bootstrap/cache; \
    mkdir -p database/factories database/seeders bootstrap/cache \
        storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs; \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction --no-scripts; \
    composer check-platform-reqs --no-dev; \
    APP_ENV=production APP_DEBUG=false php artisan package:discover --no-interaction; \
    APP_ENV=production APP_DEBUG=false php artisan filament:assets --no-interaction
ARG RELEASE_TAG=
# Only the release check reads the customer template; it stays out of /app and the final image.
COPY scripts/docker/env.example /tmp/vaultigen-env.example
RUN set -eux; \
    if [ -n "$RELEASE_TAG" ]; then APP_ENV=production php artisan vaultigen:release:check "$RELEASE_TAG" --env-template=/tmp/vaultigen-env.example --no-interaction; fi; \
    php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); file_put_contents("build.json", json_encode(["version" => config("vaultigen.version"), "release_date" => config("vaultigen.release_date")], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);'

FROM docker.io/library/node:24-trixie-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=dependencies /app/vendor ./vendor
COPY resources ./resources
COPY app ./app
COPY vite.config.js ./
RUN npm run build

FROM php-runtime AS production
ARG RELEASE_TAG=
ARG SOURCE_REVISION=local
ARG SOURCE_URL=
LABEL org.opencontainers.image.title="Vaultigen" \
    org.opencontainers.image.description="Self-hosted password manager" \
    org.opencontainers.image.vendor="Vaultigen" \
    org.opencontainers.image.url="https://vaultigen.com" \
    org.opencontainers.image.source="${SOURCE_URL}" \
    org.opencontainers.image.licenses="Elastic-2.0" \
    org.opencontainers.image.version="${RELEASE_TAG:-local}" \
    org.opencontainers.image.revision="${SOURCE_REVISION}"
COPY --from=dependencies /app /app
COPY --from=frontend /app/public/build /app/public/build
COPY scripts/docker/entrypoint.sh /app/scripts/docker/entrypoint.sh
COPY scripts/docker/Caddyfile /etc/caddy/Caddyfile
COPY scripts/docker/php.ini /usr/local/etc/php/conf.d/zz-vaultigen.ini
RUN set -eux; \
    rm -f /app/bootstrap/cache/config.php; \
    chmod -R u=rwX,go=rX /app; \
    chown -R 10001:10001 /app/storage /app/bootstrap/cache /data /config; \
    chmod 700 /app/storage /app/bootstrap/cache /data /config; \
    chmod 755 /app/scripts/docker/entrypoint.sh
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=warning \
    SESSION_SECURE_COOKIE=true DB_CONNECTION=pgsql \
    TMPDIR=/app/storage/app/private/tmp \
    XDG_DATA_HOME=/data XDG_CONFIG_HOME=/config
USER 10001:10001
HEALTHCHECK NONE
EXPOSE 8080
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/bin/tini", "--", "/app/scripts/docker/entrypoint.sh"]
CMD ["web"]
