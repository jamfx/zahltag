# Zahltag

[🇬🇧 English](README.md) | 🇩🇪 Deutsch

Selbst gehostete Web-App zum Aufteilen von Gruppenausgaben – mit SEPA-GiroCode, PayPal-/Wero-Zahllinks, PDF-/CSV-Export und 2FA-Admin-Login. PHP, ohne Framework.

Gedacht als selbst gehostete Alternative zu Splitwise & Co. – ohne Account oder App für die Teilnehmenden, Gruppen werden einfach per Link geteilt.

## Funktionen

- **Gruppenausgaben** — Gruppe anlegen, Mitglieder hinzufügen, Ausgaben erfassen, gleichmäßig oder nach individuellen Anteilen aufteilen.
- **Ausgleichen** — jedes Mitglied sieht, wer wem wie viel schuldet; das Begleichen kann manuell markiert oder direkt bezahlt werden über:
  - **SEPA-GiroCode** (scanbarer QR-Code für Banking-Apps)
  - **PayPal.me**-Link
  - **Wero**-Link
- **Belege** — Foto zu jeder Ausgabe anhängen (liegt außerhalb des direkten Web-Zugriffs, wird nur über ein PHP-Script ausgeliefert).
- **Export** — Ausgaben einer Gruppe als PDF oder CSV herunterladen.
- **Admin-Dashboard** — separater Admin-Login pro Instanz, abgesichert mit:
  - TOTP (Authenticator-App) als Zwei-Faktor-Login
  - WebAuthn / Passkeys
- **E-Mail-Versand** — Einladungslinks und Benachrichtigungen per SMTP, System-`sendmail` oder Brevo-API — konfigurierbar direkt in den Admin-Einstellungen, keine Code-Änderung nötig.
- **Mehrsprachig** — Deutsch und Englisch von Haus aus (`languages/de.json`, `languages/en.json`).
- **Geführter Installationsassistent** — ein web-basierter Installer schreibt `config.php` und legt das Datenbankschema an; kein CLI-Zugriff nötig.
- **Cron-Bereinigung** — ein optionales Standalone-Script (`cleanup.php`) löscht alte/leere Gruppen nach Zeitplan.

## Voraussetzungen

- PHP >= 8.1 mit den Erweiterungen `pdo_mysql`, `gd` und `mbstring`
- MySQL / MariaDB
- Apache mit `mod_rewrite` und `.htaccess`-Unterstützung (eine `.htaccess` liegt bei und wird für Routing und Security-Header benötigt)

Alle PHP-Abhängigkeiten (dompdf, endroid/qr-code, WebAuthn, IBAN-Validierung, …) liegen bereits fertig installiert unter `vendor/` in diesem Repository — **kein Composer auf dem Zielserver nötig**. Einfach Dateien hochladen und Installationsassistenten starten.

## Installation

1. Inhalt dieses Repositories auf den Webspace hochladen (z. B. per FTP/SFTP).
2. Sicherstellen, dass `uploads/` und dessen Unterordner von PHP beschreibbar sind.
3. Seite im Browser öffnen — solange noch keine `config.php` existiert, wird automatisch zum Installationsassistenten weitergeleitet.
4. Dem Assistenten folgen: er legt das Datenbankschema an und schreibt die `config.php`.
5. Mit den beim Setup vergebenen Admin-Zugangsdaten unter `/admin` einloggen und den E-Mail-Versand unter **Einstellungen** konfigurieren.

Für eine Neuinstallation einfach `config.php` löschen (der Installer verweigert den Start, solange die Datei existiert).

## Konfiguration

Die Laufzeit-Konfiguration liegt in `config.php` (wird vom Installer erzeugt, **niemals ins Git-Repository eingecheckt** — siehe `.gitignore`). `includes/config.example.php` zeigt den erwarteten Aufbau, falls die Datei manuell angelegt werden soll:

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

Alles Weitere (Site-Logo, Rechtstexte, E-Mail-Versandmethode/-Zugangsdaten) wird über die Admin-**Einstellungen** konfiguriert und in der Datenbank gespeichert — es sind keine weiteren Datei-Änderungen nötig.

## Sicherheitshinweise

- `config.php`, `*.log`, `*.sql`, `*.lock` und ähnliche Dateien werden bereits auf Webserver-Ebene per `.htaccess` gesperrt.
- Belegfotos unter `uploads/receipts/` sind vor direktem URL-Zugriff geschützt und werden nur über einen authentifizierten PHP-Endpunkt ausgeliefert.
- Der Admin-Login unterstützt zusätzlich zum Passwort TOTP und WebAuthn/Passkeys.

## Technik

Reines PHP (kein Framework), MySQL/MariaDB, Vanilla-JS/CSS im Frontend. Wichtigste Bibliotheken: [dompdf](https://github.com/dompdf/dompdf) (PDF-Export), [endroid/qr-code](https://github.com/endroid/qr-code) (GiroCode-/TOTP-QR-Codes), [lbuchs/webauthn](https://github.com/lbuchs/webauthn) (Passkeys), [jschaedl/iban-validation](https://github.com/jschaedl/iban-validation).

## Lizenz

AGPL-3.0-or-later — siehe [LICENSE.txt](LICENSE.txt).

Copyright (C) 2026 Niko Winckel – [n-systeme.de](https://n-systeme.de)
