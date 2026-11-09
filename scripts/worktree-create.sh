#!/usr/bin/env bash
set -euo pipefail

# Resolve the worktree from this script, even when a hook runs from another directory.
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

for command in php composer npm; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Missing required command: $command" >&2
        exit 1
    fi
done

php -r '
    foreach (["pdo_sqlite", "sodium"] as $extension) {
        if (! extension_loaded($extension)) {
            fwrite(STDERR, "Missing required PHP extension: {$extension}\n");
            exit(1);
        }
    }
'

mkdir -p bootstrap/cache storage/app/private storage/app/public \
    storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

# Keep worktree data independent. Preserve other settings, including a local server URL.
php <<'PHP'
<?php
$environment = is_file('.env') ? file_get_contents('.env') : file_get_contents('.env.example');
$settings = [
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'true',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => 'database/database.sqlite',
    'DB_URL' => 'null',
    'SESSION_DRIVER' => 'database',
    'SESSION_DOMAIN' => 'null',
    'QUEUE_CONNECTION' => 'database',
    'CACHE_STORE' => 'database',
];

foreach ($settings as $name => $value) {
    $pattern = '/^[ \t]*(?:export[ \t]+)?'.preg_quote($name, '/').'[ \t]*=.*$/m';
    $line = $name.'='.$value;
    $environment = preg_match($pattern, $environment)
        ? preg_replace($pattern, $line, $environment)
        : rtrim($environment)."\n".$line."\n";
}

// Laravel's AES-256-CBC key format. Never rotate an existing application's key.
$pattern = '/^[ \t]*(?:export[ \t]+)?APP_KEY[ \t]*=(.*)$/m';
$hasKey = preg_match($pattern, $environment, $match);
$key = $hasKey ? trim(trim(explode(' #', $match[1], 2)[0]), "\"' \t\r") : '';
if (in_array(strtolower($key), ['', 'null', '(null)', 'empty', '(empty)'], true)) {
    $line = 'APP_KEY=base64:'.base64_encode(random_bytes(32));
    $environment = $hasKey
        ? preg_replace($pattern, $line, $environment)
        : rtrim($environment)."\n".$line."\n";
}

if (file_put_contents('.env', $environment) === false) {
    exit(1);
}
chmod('.env', 0600);
PHP

touch database/database.sqlite

# Discard copied configuration before Composer's Artisan hooks boot the app.
rm -f bootstrap/cache/config.php
composer install --no-interaction --prefer-dist

# The local seeder also creates missing installation identity and encryption keys.
php artisan migrate --seed --no-interaction
php artisan optimize:clear --no-interaction
if [[ ! -e public/storage && ! -L public/storage ]]; then
    php artisan storage:link --no-interaction
fi

npm ci
npm run build

echo
echo 'Worktree setup complete. Start the application with: composer run dev'
echo 'Local login: admin@admin.com / password'
echo 'MFA follows .env; set VAULTIGEN_MFA_DISABLED=true for password-only local sign-in.'
