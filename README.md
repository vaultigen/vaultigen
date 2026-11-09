# Vaultigen

Vaultigen is a self-hosted password manager for teams, with one organization per installation. It runs on your own server, and its source code is available so you can see how your secrets are handled.

It is built with Laravel, Filament, and Livewire.

## Features

- Vaults shared with users and teams, with Viewer, Editor, and Manager access, plus a personal work vault for each user
- Login, secure note, SSH key, and TOTP entries with revision history
- Encrypted file attachments
- Multi-factor authentication with an authenticator app or email code
- A tamper-evident audit log and emergency access
- Encrypted backups, restore, and key rotation
- Imports from sysPass and KeePass

## Security model

Vaultigen encrypts stored data but trusts the server operator: the running application and its host operator can access secrets. It is not a zero-knowledge design. The [security model](https://vaultigen.com/docs/security-model) explains what is protected and what is not.

Please report vulnerabilities privately, as described in [reporting a vulnerability](https://vaultigen.com/docs/vulnerability-reporting).

## Documentation

The documentation is on [vaultigen.com/docs](https://vaultigen.com/docs/):

- [Requirements](https://vaultigen.com/docs/requirements)
- [Installation with Docker Compose](https://vaultigen.com/docs/installation) (recommended)
- [Manual installation](https://vaultigen.com/docs/manual-installation)
- [First steps](https://vaultigen.com/docs/first-steps)
- [Upgrading](https://vaultigen.com/docs/upgrading)
- [Backups](https://vaultigen.com/docs/backups) and [restore](https://vaultigen.com/docs/restore)
- [Hardening](https://vaultigen.com/docs/hardening)
- [Troubleshooting](https://vaultigen.com/docs/troubleshooting)

## License

Vaultigen is source-available under the [Elastic License 2.0](LICENSE). Production use is free for organizations with up to 10 users, with every feature included. Larger organizations need a commercial license ([pricing](https://vaultigen.com/pricing)). The license does not allow offering Vaultigen as a hosted service or bypassing its license check. IT service providers may run a separate installation for each client ([permission](docs/legal/service-provider-permission.md)).

Licenses are verified offline. Vaultigen never contacts its vendor: no telemetry, no online activation. See the [licensing overview](https://vaultigen.com/docs/licensing).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the local development setup and the [Contributor License Agreement](docs/legal/cla.md).
