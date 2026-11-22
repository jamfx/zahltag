# Changelog

All notable changes to Zahltag are documented here.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows [Semantic Versioning](https://semver.org/).

[Deutsche Version](CHANGELOG.de.md)

## [1.1.0] - 2026-11-22

### Added
- **Automatic archiving of groups.** Groups are archived automatically when they are inactive for a long time (default: 9 months) or when they are settled (default: after 1 month). Activity means new, edited or deleted expenses, payments and new or deactivated members.
- A group counts as **settled** when it has at least one expense, all balances are zero and no unconfirmed payments are open.
- **Notice banner** on all group pages for members and the group admin, announcing the archiving date. It appears 30 days before archiving (inactive groups) or immediately (settled groups). New activity cancels the archiving.
- The **group admin can extend** the archiving date (default: 1 month per click, unlimited).
- All periods are configurable in Admin → Settings → General: inactivity period, banner lead time, period for settled groups, extension, deletion period for archived groups and deletion period for empty groups. `0` disables a rule.
- Help texts (FAQ, group settings, privacy notes) show the configured periods dynamically.

### Changed
- Archived groups are now deleted after 30 days by default (new installations; existing installations keep their configured value).
- Empty groups without expenses have their own deletion period (default: 90 days) instead of sharing the one for archived groups.
- The cron job now also schedules and performs the automatic archiving.

### Fixed
- Admin settings: the "Save" button in the General section did nothing as soon as a logo was uploaded (nested forms). Impressum, privacy policy and retention settings can be saved again.
- Groups with payments could not be deleted (cron cleanup, admin dashboard and group settings) because of a foreign key constraint; the failure was silent. Groups are now deleted completely, including payments, expenses and members.
- GiroCode and TOTP QR codes: PNG is used when the GD extension is available, otherwise SVG. Previously the QR code was broken without GD. Errors are now written to the PHP error log.
- Umlauts and special characters in group names no longer break the file names of PDF and CSV exports.

### Upgrade notes
- Replace the changed files; no manual migration is required. The new column `groups.archive_at` is created automatically on the first cron or admin dashboard call. If the database user lacks `ALTER` privileges, run: `ALTER TABLE groups ADD COLUMN archive_at DATETIME NULL AFTER archived_at;`
- On the first cron run, groups that have already been inactive for more than 8 months get the notice banner immediately and are archived after the full lead time.

## [1.0.2] - 2026-09-07

### Fixed
- Language switch on mobile devices.

## [1.0.1] - 2026-09-05

### Changed
- Updated `endroid/qr-code` (5 → 6) and `smhg/sepa-qr-data` (1 → 3); GiroCode and TOTP QR generation adapted to the new APIs.
- Updated `masterminds/html5` to 2.11.0.

## [1.0.0] - 2026-09-05

First release of Zahltag.

[1.1.0]: https://github.com/jamfx/zahltag/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/jamfx/zahltag/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/jamfx/zahltag/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/jamfx/zahltag/releases/tag/v1.0.0
