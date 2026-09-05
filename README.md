# Zahltag

🇬🇧 English | [🇩🇪 Deutsch](README.de.md)

Self-hosted group expense splitting app with SEPA GiroCode, PayPal & Wero payment links, PDF/CSV export and 2FA admin login. PHP, no framework.

Think Splitwise, but self-hosted on your own webspace, with no account or app required for participants — groups are shared via a link.

## Features

- **Group expenses** — create a group, add members, log expenses, split them evenly or by custom shares.
- **Settle up** — each member sees who owes whom and how much; settling can be marked manually or paid directly via:
  - **SEPA GiroCode** (scannable QR code for bank transfer apps)
  - **PayPal.me** link
  - **Wero** link
- **Receipts** — attach a photo to any expense (stored outside the web root's direct reach, served only through a PHP script).
- **Export** — download a group's expenses as PDF or CSV.
- **Admin dashboard** — separate admin login per instance, protected with:
  - TOTP (authenticator app) two-factor login
  - WebAuthn / Passkeys
- **Email** — invite links and notifications via SMTP, system `sendmail`, or the Brevo API — configurable in the admin settings, no code changes needed.
- **Multi-language** — German and English out of the box (`languages/de.json`, `languages/en.json`).
- **Guided installer** — a web-based install wizard writes `config.php` and sets up the database schema; no CLI access required.
- **Cron cleanup** — an optional standalone script (`cleanup.php`) purges old/empty groups on a schedule.

## Requirements

- PHP >= 8.1 with the `pdo_mysql`, `gd`, and `mbstring` extensions
- MySQL / MariaDB
- Apache with `mod_rewrite` and `.htaccess` support (an `.htaccess` is included and required for routing and security headers)

All PHP dependencies (dompdf, endroid/qr-code, WebAuthn, IBAN validation, …) are already vendored in this repository under `vendor/` — **no Composer required** on the target server. You can simply upload the files and run the installer.

## Installation

1. Upload the contents of this repository to your webspace (e.g. via FTP/SFTP).
2. Make sure `uploads/` and its subfolders are writable by PHP.
3. Open the site in a browser — you'll be redirected to the install wizard automatically as long as no `config.php` exists yet.
4. Follow the wizard: it creates the database schema and writes `config.php` for you.
5. Log in to `/admin` with the admin credentials you set during install, and configure email delivery under **Settings**.

To reinstall, simply delete `config.php` (the installer refuses to run while it exists).

## Configuration

Runtime configuration lives in `config.php` (created by the installer, **never committed to git** — see `.gitignore`). Use `includes/config.example.php` as a reference for the expected structure if you need to set it up manually:

```php
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'zahltag',
        'user' => 'db_user',
        'password' => 'db_password',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'secret' => 'RANDOM_32_BYTE_HEX_STRING_HERE', // random_bytes(32) hex
        'debug' => false,
        'base_url' => 'https://example.com',
    ],
];
```

Everything else (site logo, legal notice text, email delivery method/credentials) is configured through the admin **Settings** page and stored in the database — no further file edits needed.

## Security notes

- `config.php`, `*.log`, `*.sql`, `*.lock` and similar files are denied at the webserver level via `.htaccess`.
- Receipt photos under `uploads/receipts/` are blocked from direct URL access and served only through an authenticated PHP endpoint.
- Admin login supports TOTP and WebAuthn/Passkeys in addition to a password.

## Tech stack

Plain PHP (no framework), MySQL/MariaDB, vanilla JS/CSS on the frontend. Key libraries: [dompdf](https://github.com/dompdf/dompdf) (PDF export), [endroid/qr-code](https://github.com/endroid/qr-code) (GiroCode/TOTP QR codes), [lbuchs/webauthn](https://github.com/lbuchs/webauthn) (Passkeys), [jschaedl/iban-validation](https://github.com/jschaedl/iban-validation).

## License

AGPL-3.0-or-later — see [LICENSE.txt](LICENSE.txt).

Copyright (C) 2026 Niko Winckel – [n-systeme.de](https://n-systeme.de)
