# Contributing to Vaultigen

Thank you for your interest in Vaultigen.

## Contributor License Agreement

Vaultigen is licensed under the Elastic License 2.0 (see [LICENSE](LICENSE)) and is also sold under commercial licenses. Before we can merge a pull request, every contributor must sign the [Contributor License Agreement](docs/legal/cla.md). The CLA Assistant bot asks you to sign when you open your first pull request. You keep the copyright in your contribution.

## Security issues

Do not open public issues for security vulnerabilities. Report them privately to `hello AT shuttlework.com`.

## Pull requests

- Follow the conventions in the surrounding code. PHP is formatted with `vendor/bin/pint`.
- Add or update Pest tests for behavior changes, and make sure `php artisan test --compact` passes.
- Add every user-facing string to both `lang/en` and `lang/de`.
- Any change that lets an additional user sign in (a new invitation, import, SSO or provisioning path) must go through `App\Services\Licensing\SeatLimit`.
- Vaultigen never makes network calls to its vendor. Pull requests that add telemetry, phone-home or online license checks will not be accepted.

## Local development

Use PHP 8.5 with sodium and PDO SQLite, Composer 2, and Node.js 22.12+ with npm. SQLite needs no separate database server. Run from the repository root:

```bash
composer setup
composer run dev
```

`composer setup` installs dependencies, creates `.env` if missing, generates `APP_KEY`, migrates and seeds the database, and builds the theme. It regenerates `APP_KEY`, so do not rerun it against encrypted data you want to keep. `composer run dev` starts the web server, queue listener, Vite watcher, and log viewer.

Open the address printed by the web server and sign in with `admin@admin.com` / `password`. This account and the example data below are seeded **only when `APP_ENV=local`**.

| Account | Email | Purpose |
| --- | --- | --- |
| Manager Ben | `manager@manager.com` | Vault Manager |
| Editor Eva | `editor@editor.com` | Vault Editor |
| Viewer Tom | `viewer@viewer.com` | Vault Viewer |

Every account uses the password `password`. Multi-factor authentication is required by default, so the first sign-in asks you to enroll a method. To skip it locally, add `VAULTIGEN_MFA_DISABLED=true` to `.env`; the application refuses to start with this flag outside `local` and `testing`.

To reset the local database and restore the seeded accounts:

```bash
php artisan migrate:fresh --seed
```

The scheduler is not part of `composer run dev`. To exercise heartbeats and security notifications, run `php artisan schedule:work --no-interaction` in a second terminal.

## Tests

```bash
# Fast feedback without coverage:
php artisan test --compact

# Full quality gate: formatting, PHPStan, tests, and 100% application line coverage:
composer test
```

The coverage command requires Xdebug. Tests normally use an in-memory SQLite database and synthetic security keys.

To run the suite against MySQL and PostgreSQL, copy the example config and fill in the connection details for each driver. Each test database must already exist.

```bash
cp .env.testing.databases.example .env.testing.databases
composer test:databases
```

## Where to look first

- `app/Providers/Filament/AppPanelProvider.php`: panel configuration and navigation.
- `app/Auth/`: customized login, profile, second-factor, and trusted-device controls.
- `app/Filament/Pages/` and `resources/views/filament/`: application screens.
- `app/Console/Commands/`: installation, key management, recovery, and operational commands.
- `app/Services/`: settings, audit integrity, vault access, reauthentication, recovery, and health checks.
- `app/Services/Crypto/`: envelope encryption, the installation keyring, and vault key wrapping.
- `routes/console.php`: scheduled tasks.
- `tests/Feature/`: executable examples of supported behavior and security boundaries.
