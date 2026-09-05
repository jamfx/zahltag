<?php
declare(strict_types=1);

/*
 * Hilfe- & FAQ-Seite. Statischer Inhalt (kein site_settings-Feld) — Sprache
 * folgt der aktuell gewählten Frontend-Sprache. Neue Sprache: eine
 * templates/partials/help_{locale}.php anlegen, sonst greift der
 * Deutsch-Fallback.
 */

$helpLocale = Translator::getLang();
$pageTitle  = __('nav.help');

ob_start();
$partial = BASE_PATH . '/templates/partials/help_' . $helpLocale . '.php';
if (!file_exists($partial)) {
    $partial = BASE_PATH . '/templates/partials/help_de.php';
}
require $partial;
$content = ob_get_clean();

require BASE_PATH . '/templates/layout.php';
