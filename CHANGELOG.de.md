# Changelog

Alle wesentlichen Änderungen an Zahltag werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

[English version](CHANGELOG.md)

## [1.1.0] - 2026-11-22

### Neu
- **Automatische Archivierung von Gruppen.** Gruppen werden automatisch archiviert, wenn sie lange inaktiv sind (Standard: 9 Monate) oder abgeschlossen sind (Standard: nach 1 Monat). Als Aktivität zählen neue, geänderte oder gelöschte Ausgaben, Zahlungen sowie neue oder deaktivierte Mitglieder.
- Eine Gruppe gilt als **abgeschlossen**, wenn sie mindestens eine Ausgabe hat, alle Salden ausgeglichen sind und keine unbestätigten Zahlungen offen sind.
- **Hinweis-Banner** auf allen Gruppenseiten für Mitglieder und Gruppen-Admin mit dem Archivierungstermin. Er erscheint 30 Tage vor der Archivierung (inaktive Gruppen) bzw. sofort (abgeschlossene Gruppen). Neue Aktivität hebt die Archivierung auf.
- Der **Gruppen-Admin kann** den Archivierungstermin **verlängern** (Standard: 1 Monat pro Klick, beliebig oft).
- Alle Fristen sind unter Admin → Einstellungen → Allgemein einstellbar: Inaktivitätsfrist, Banner-Vorlauf, Frist für abgeschlossene Gruppen, Verlängerung, Löschfrist für archivierte Gruppen und Löschfrist für leere Gruppen. `0` deaktiviert die jeweilige Regel.
- Die Hilfetexte (FAQ, Gruppen-Einstellungen, Datenschutz-Hinweise) zeigen die eingestellten Fristen dynamisch an.

### Geändert
- Archivierte Gruppen werden standardmäßig nach 30 Tagen gelöscht (Neuinstallationen; bestehende Installationen behalten ihren eingestellten Wert).
- Leere Gruppen ohne Ausgaben haben eine eigene Löschfrist (Standard: 90 Tage) und teilen sich diese nicht mehr mit den archivierten Gruppen.
- Der Cronjob plant und führt nun auch die automatische Archivierung aus.

### Behoben
- Admin-Einstellungen: Der Speichern-Knopf im Bereich „Allgemein“ war wirkungslos, sobald ein Logo hochgeladen war (verschachtelte Formulare). Impressum, Datenschutzerklärung und Fristen lassen sich wieder speichern.
- Gruppen mit Zahlungen konnten wegen eines Fremdschlüssels nicht gelöscht werden (Cron-Bereinigung, Admin-Dashboard und Gruppen-Einstellungen), der Fehler blieb unbemerkt. Gruppen werden nun vollständig gelöscht, inklusive Zahlungen, Ausgaben und Mitgliedern.
- GiroCode- und TOTP-QR-Codes: PNG wird verwendet, wenn die GD-Erweiterung vorhanden ist, sonst SVG. Zuvor war der QR-Code ohne GD defekt. Fehler werden jetzt ins PHP-Fehlerlog geschrieben.
- Umlaute und Sonderzeichen in Gruppennamen führen nicht mehr zu fehlerhaften Dateinamen bei PDF- und CSV-Export.

### Hinweise zum Update
- Geänderte Dateien ersetzen; eine manuelle Migration ist nicht nötig. Die neue Spalte `groups.archive_at` wird beim ersten Cron- oder Dashboard-Aufruf automatisch angelegt. Fehlen dem Datenbankbenutzer die `ALTER`-Rechte, einmalig ausführen: `ALTER TABLE groups ADD COLUMN archive_at DATETIME NULL AFTER archived_at;`
- Beim ersten Cron-Lauf erhalten Gruppen, die bereits länger als 8 Monate inaktiv sind, sofort den Hinweis-Banner und werden nach der vollen Vorlaufzeit archiviert.

## [1.0.2] - 2026-09-07

### Behoben
- Sprachumschalter auf mobilen Geräten.

## [1.0.1] - 2026-09-05

### Geändert
- `endroid/qr-code` (5 → 6) und `smhg/sepa-qr-data` (1 → 3) aktualisiert; GiroCode- und TOTP-QR-Erzeugung an die neuen APIs angepasst.
- `masterminds/html5` auf 2.11.0 aktualisiert.

## [1.0.0] - 2026-09-05

Erste Veröffentlichung von Zahltag.

[1.1.0]: https://github.com/jamfx/zahltag/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/jamfx/zahltag/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/jamfx/zahltag/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/jamfx/zahltag/releases/tag/v1.0.0
