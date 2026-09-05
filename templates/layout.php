<?php
declare(strict_types=1);

/*
 * layout.php – HTML-Grundgerüst
 *
 * Verwendung in Pages:
 *   $pageTitle = 'Seitentitel';
 *   ob_start();
 *   // … HTML-Inhalt …
 *   $content = ob_get_clean();
 *   require BASE_PATH . '/templates/layout.php';
 *
 * Optional: $bodyClass, $navLinks (array of ['url','label','active'])
 */

$siteName     = setting('site_name', 'Zahltag');
$primaryColor = setting('primary_color', '#2d6a4f');
$siteLogo     = setting('site_logo', '');
$flashMessages = get_flash();
$availableLangs = Translator::getAvailableLanguages();
$currentLang  = Translator::getLang();
$pageTitle    = isset($pageTitle) ? e($pageTitle) . ' – ' . e($siteName) : e($siteName);
$bodyClass    = $bodyClass ?? '';
$navLinks     = $navLinks ?? [];
?>
<!DOCTYPE html>
<html lang="<?= e($currentLang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $pageTitle ?></title>
<link rel="stylesheet" href="<?= base_url('assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/vendor/fontawesome/css/all.min.css') ?>">
<style>
:root {
    --color-primary:      <?= e($primaryColor) ?>;
    --color-primary-dark: color-mix(in srgb, <?= e($primaryColor) ?> 75%, #000);
    --color-primary-light:color-mix(in srgb, <?= e($primaryColor) ?> 15%, #fff);
}
</style>
</head>
<body class="<?= e($bodyClass) ?>"
      data-cm-cancel="<?= e(__('common.cancel')) ?>"
      data-cm-ok="<?= e(__('common.confirm')) ?>">

<a href="#main-content" class="skip-link"><?= e(__('nav.skip_to_content')) ?></a>

<header class="site-header" role="banner">
    <div class="container header-inner">

        <a href="<?= base_url() ?>" class="site-brand" aria-label="<?= e($siteName) ?> – <?= e(__('nav.home')) ?>">
            <?php if ($siteLogo && file_exists(BASE_PATH . '/' . ltrim($siteLogo, '/'))): ?>
                <img src="<?= e(base_url($siteLogo)) ?>" alt="" class="site-logo" height="36">
            <?php endif; ?>
            <span class="site-name"><?= e($siteName) ?></span>
        </a>

        <?php if (!empty($navLinks)): ?>
        <button class="nav-toggle" aria-expanded="false" aria-controls="site-nav" aria-label="Menü">
            <span class="hamburger"></span>
        </button>
        <?php endif; ?>

        <nav class="site-nav" id="site-nav" aria-label="Hauptnavigation">
            <?php if (!empty($navLinks)): ?>
            <ul class="nav-list">
                <?php foreach ($navLinks as $link): ?>
                <li>
                    <?php if (($link['type'] ?? '') === 'dropdown'): ?>
                    <details class="nav-dropdown">
                        <summary class="nav-link<?= !empty($link['active']) ? ' nav-link--active' : '' ?>">
                            <?php if (!empty($link['icon'])): ?><i class="<?= e($link['icon']) ?>" aria-hidden="true"></i> <?php endif; ?><?= e($link['label']) ?><i class="fa-solid fa-chevron-down nav-dropdown__chevron" aria-hidden="true"></i>
                        </summary>
                        <ul class="nav-dropdown__menu">
                            <?php foreach ($link['children'] as $child): ?>
                            <li>
                                <a href="<?= e($child['url']) ?>"
                                   class="nav-link<?= !empty($child['active']) ? ' nav-link--active' : '' ?>"
                                   <?= !empty($child['active']) ? 'aria-current="page"' : '' ?>>
                                    <?php if (!empty($child['icon'])): ?><i class="<?= e($child['icon']) ?>" aria-hidden="true"></i> <?php endif; ?><?= e($child['label']) ?>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                    <?php elseif (($link['type'] ?? '') === 'logout'): ?>
                    <form method="post" action="<?= base_url('admin/logout') ?>" style="display:contents">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <button type="submit" class="nav-link nav-link--btn">
                            <?php if (!empty($link['icon'])): ?><i class="<?= e($link['icon']) ?>" aria-hidden="true"></i> <?php endif; ?><?= e($link['label']) ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <a href="<?= e($link['url']) ?>"
                       class="nav-link<?= !empty($link['active']) ? ' nav-link--active' : '' ?>"
                       <?= !empty($link['active']) ? 'aria-current="page"' : '' ?>>
                        <?php if (!empty($link['icon'])): ?><i class="<?= e($link['icon']) ?>" aria-hidden="true"></i> <?php endif; ?><?= e($link['label']) ?>
                    </a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php if (count($availableLangs) > 1): ?>
            <select class="lang-select" aria-label="<?= e(__('nav.language')) ?>" onchange="location.href='?lang='+this.value">
                <?php foreach ($availableLangs as $l): ?>
                <option value="<?= e($l['code']) ?>"<?= $l['code'] === $currentLang ? ' selected' : '' ?>>
                    <?= e(strtoupper($l['code'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </nav>

    </div>
</header>
<div class="nav-backdrop" id="nav-backdrop" aria-hidden="true"></div>

<main class="site-main" id="main-content" role="main">
    <div class="container">

        <?php foreach ($flashMessages as $msg): ?>
        <?php
        $flashIcon = match($msg['type']) {
            'success' => 'fa-solid fa-circle-check',
            'error'   => 'fa-solid fa-circle-exclamation',
            'warning' => 'fa-solid fa-triangle-exclamation',
            default   => 'fa-solid fa-circle-info',
        };
        $flashRole = $msg['type'] === 'error' ? 'alert' : 'status';
        $flashLive = $msg['type'] === 'error' ? 'assertive' : 'polite';
        ?>
        <div class="flash flash--<?= e($msg['type']) ?>" role="<?= $flashRole ?>" aria-live="<?= $flashLive ?>">
            <i class="<?= $flashIcon ?>" aria-hidden="true"></i>
            <span><?= e($msg['message']) ?></span>
            <button class="flash__close" aria-label="<?= e(__('common.close')) ?>" type="button">×</button>
        </div>
        <?php endforeach; ?>

        <?= $content ?? '' ?>

    </div>
</main>

<footer class="site-footer" role="contentinfo">
    <div class="container">
        <p>
            <?= e(__('app.copyright', ['year' => date('Y'), 'name' => $siteName])) ?>
            &nbsp;·&nbsp;
            <a href="<?= base_url('hilfe') ?>">
                <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
                <?= e(__('nav.help')) ?>
            </a>
            &nbsp;·&nbsp;
            <a href="<?= base_url('impressum') ?>"><?= e(__('nav.impressum')) ?></a>
            &nbsp;·&nbsp;
            <a href="<?= base_url('datenschutz') ?>"><?= e(__('nav.datenschutz')) ?></a>
        </p>
        <p class="text-muted text-sm">
            <i class="fa-brands fa-osi" aria-hidden="true"></i>
            <?= e(__('footer.license_text')) ?>
            &nbsp;·&nbsp;
            <a href="https://github.com/jamfx/zahltag" target="_blank" rel="noopener noreferrer">
                <i class="fa-brands fa-github" aria-hidden="true"></i>
                <?= e(__('footer.source_link')) ?>
            </a>
        </p>
    </div>
</footer>

<script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
