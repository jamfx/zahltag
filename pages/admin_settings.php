<?php
declare(strict_types=1);

require_admin();

$currencies = ['EUR', 'USD', 'GBP', 'CHF', 'JPY', 'CNY'];
$errors     = [];

// Load current site_settings
$siteSettings = [];
try {
    $rows = db()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
    foreach ($rows as $row) {
        $siteSettings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Throwable) {}

// Load email_settings (single row)
$emailSettings = [];
try {
    $emailSettings = db()->query('SELECT * FROM email_settings LIMIT 1')->fetch() ?: [];
} catch (Throwable) {}

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'save_general';

    if ($action === 'save_general') {
        $siteName        = trim($_POST['site_name']          ?? 'Zahltag');
        $primaryColor    = trim($_POST['primary_color']      ?? '#2563eb');
        $defaultCurrency = trim($_POST['default_currency']   ?? 'EUR');
        $multiCurrency   = isset($_POST['multi_currency_enabled']) ? '1' : '0';
        $maxReceipt      = max(1, min(50, (int)($_POST['max_receipt_size_mb'] ?? 5)));
        $cleanupDays     = max(0, (int)($_POST['cleanup_days'] ?? 90));
        $pdfMarginTop    = max(0.0, min(5.0, round((float)($_POST['pdf_margin_top']    ?? 1.0), 1)));
        $pdfMarginRight  = max(0.0, min(5.0, round((float)($_POST['pdf_margin_right']  ?? 1.0), 1)));
        $pdfMarginBottom = max(0.0, min(5.0, round((float)($_POST['pdf_margin_bottom'] ?? 1.0), 1)));
        $pdfMarginLeft   = max(0.0, min(5.0, round((float)($_POST['pdf_margin_left']   ?? 2.5), 1)));
        $impressum       = $_POST['impressum_text']          ?? '';
        $datenschutz     = $_POST['datenschutz_text']        ?? '';

        if (!in_array($defaultCurrency, $currencies, true)) $defaultCurrency = 'EUR';
        if (!preg_match('/^#[0-9a-f]{6}$/i', $primaryColor)) $primaryColor = '#2563eb';

        $updates = [
            'site_name'               => mb_substr($siteName, 0, 100) ?: 'Zahltag',
            'primary_color'           => $primaryColor,
            'default_currency'        => $defaultCurrency,
            'multi_currency_enabled'  => $multiCurrency,
            'max_receipt_size_mb'     => (string)$maxReceipt,
            'cleanup_days'            => (string)$cleanupDays,
            'pdf_margin_top'          => (string)$pdfMarginTop,
            'pdf_margin_right'        => (string)$pdfMarginRight,
            'pdf_margin_bottom'       => (string)$pdfMarginBottom,
            'pdf_margin_left'         => (string)$pdfMarginLeft,
            'impressum_text'          => $impressum,
            'datenschutz_text'        => $datenschutz,
        ];

        // Logo upload – saved as PNG to preserve transparency
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
            $logoTmp  = $_FILES['site_logo']['tmp_name'];
            $logoSize = $_FILES['site_logo']['size'];
            $logoMime = mime_content_type($logoTmp) ?: '';

            if ($logoSize > 512_000) {
                $errors[] = __('admin.settings.site_logo') . ': ' . __('validation.too_long', ['max' => '500 KB']);
            } elseif (!in_array($logoMime, ['image/png', 'image/jpeg'], true)) {
                $errors[] = __('admin.settings.site_logo') . ': ' . __('expense.validation.receipt_invalid_type');
            } else {
                $destDir = BASE_PATH . '/uploads';
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                foreach (['jpg', 'png'] as $oldExt) {
                    $old = $destDir . '/logo.' . $oldExt;
                    if (file_exists($old)) @unlink($old);
                }
                $dest = $destDir . '/logo.png';
                $img  = match ($logoMime) {
                    'image/jpeg' => @imagecreatefromjpeg($logoTmp),
                    'image/png'  => @imagecreatefrompng($logoTmp),
                    default      => false,
                };
                if ($img === false) {
                    $errors[] = __('admin.settings.site_logo') . ': ' . __('common.error');
                } else {
                    imagesavealpha($img, true);
                    if (imagepng($img, $dest)) {
                        $updates['site_logo'] = 'uploads/logo.png';
                    } else {
                        $errors[] = __('admin.settings.site_logo') . ': ' . __('common.error');
                    }
                    imagedestroy($img);
                }
            }
        }

        if (empty($errors)) {
            try {
                $stmt = db()->prepare(
                    'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = ?'
                );
                foreach ($updates as $k => $v) {
                    $stmt->execute([$k, $v, $v]);
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
            if (empty($errors)) {
                flash('success', __('admin.settings.save_success'));
                redirect('/admin/settings');
            }
        }

    } elseif ($action === 'save_email') {
        $method    = in_array($_POST['email_method'] ?? '', ['smtp', 'sendmail', 'brevo'], true)
                     ? $_POST['email_method'] : 'smtp';
        $smtpHost  = trim($_POST['smtp_host']      ?? '');
        $smtpPort  = max(1, min(65535, (int)($_POST['smtp_port'] ?? 587)));
        $smtpUser  = trim($_POST['smtp_user']      ?? '');
        $smtpPass  = trim($_POST['smtp_pass']      ?? '');
        $smtpEnc   = in_array($_POST['smtp_enc']   ?? '', ['tls', 'ssl', 'none'], true)
                     ? $_POST['smtp_enc'] : 'tls';
        $brevoKey  = trim($_POST['brevo_api_key']  ?? '');
        $fromEmail = trim($_POST['from_email']     ?? '');
        $fromName  = trim($_POST['from_name']      ?? '');

        try {
            // Upsert the single email_settings row
            $existing = db()->query('SELECT id FROM email_settings LIMIT 1')->fetch();
            if ($existing) {
                $stmt = db()->prepare(
                    'UPDATE email_settings SET method=?, smtp_host=?, smtp_port=?, smtp_user=?,
                     smtp_pass=?, smtp_encryption=?, brevo_api_key=?, from_email=?, from_name=?
                     WHERE id=?'
                );
                $stmt->execute([
                    $method, $smtpHost ?: null, $smtpPort, $smtpUser ?: null,
                    $smtpPass ?: null, $smtpEnc, $brevoKey ?: null,
                    $fromEmail ?: null, $fromName ?: null,
                    (int)$existing['id'],
                ]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO email_settings
                     (method, smtp_host, smtp_port, smtp_user, smtp_pass, smtp_encryption,
                      brevo_api_key, from_email, from_name)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $method, $smtpHost ?: null, $smtpPort, $smtpUser ?: null,
                    $smtpPass ?: null, $smtpEnc, $brevoKey ?: null,
                    $fromEmail ?: null, $fromName ?: null,
                ]);
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if (empty($errors)) {
            flash('success', __('admin.settings.save_success'));
            redirect('/admin/settings');
        }

    } elseif ($action === 'test_mail') {
        $testTo = trim($_POST['test_mail_to'] ?? '');
        if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('admin.settings.email_test_error', ['error' => 'Invalid email address']);
        } else {
            require_once BASE_PATH . '/includes/Mailer.php';
            $mailer  = Mailer::fromSettings();
            $siteName = setting('site_name', 'Zahltag');
            $subject  = 'Testmail aus ' . $siteName . ' – ' . base_url();
            $html    = '<p>' . htmlspecialchars(setting('site_name', 'Zahltag'), ENT_QUOTES) . ' – Test-E-Mail. Wenn du diese Nachricht siehst, funktioniert der E-Mail-Versand korrekt.</p>';
            $ok = $mailer->send($testTo, $subject, $html);
            if ($ok) {
                flash('success', __('admin.settings.email_test_success'));
            } else {
                flash('error', __('admin.settings.email_test_error', ['error' => $mailer->getLastError() ?? 'unknown']));
            }
            redirect('/admin/settings');
        }

    } elseif ($action === 'delete_logo') {
        $currentLogo = $siteSettings['site_logo'] ?? '';
        if ($currentLogo !== '') {
            $absPath = BASE_PATH . '/' . ltrim($currentLogo, '/');
            if (file_exists($absPath)) @unlink($absPath);
            try { setting_set('site_logo', ''); } catch (Throwable) {}
        }
        flash('success', __('admin.settings.save_success'));
        redirect('/admin/settings');

    } elseif ($action === 'run_cleanup') {
        try {
            cleanup_archived_groups();
            setting_set('last_cron_run', date('Y-m-d H:i:s'));
            flash('success', __('admin.settings.cron_run_success'));
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/settings');
    }

    // Reload after failed save
    try {
        $rows = db()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll();
        foreach ($rows as $row) $siteSettings[$row['setting_key']] = $row['setting_value'];
    } catch (Throwable) {}
    try {
        $emailSettings = db()->query('SELECT * FROM email_settings LIMIT 1')->fetch() ?: [];
    } catch (Throwable) {}
}

// ─── Render ───────────────────────────────────────────────────────────────────
$pageTitle = __('admin.settings.title');
$navLinks  = [
    ['url' => base_url('admin/dashboard'), 'label' => __('admin.dashboard.title'), 'icon' => 'fa-solid fa-gauge',       'active' => false],
    ['url' => base_url('admin/settings'),  'label' => __('admin.settings.title'),  'icon' => 'fa-solid fa-sliders',     'active' => true],
    ['url' => base_url('admin/profile'),   'label' => __('admin.nav.profile'),     'icon' => 'fa-solid fa-circle-user', 'active' => false],
    ['type' => 'logout', 'label' => __('admin.login.logout'), 'icon' => 'fa-solid fa-right-from-bracket'],
];

$s   = $siteSettings;
$em  = $emailSettings;
$logoPath = $s['site_logo'] ?? '';

// Ensure cron token exists
$cronToken = $s['cron_token'] ?? '';
if ($cronToken === '') {
    $cronToken = bin2hex(random_bytes(24));
    try {
        setting_set('cron_token', $cronToken);
    } catch (Throwable) {}
}
$cronUrl     = base_url('cron/cleanup') . '?token=' . urlencode($cronToken);
$lastCronRun = $s['last_cron_run'] ?? '';

// PHP-Binary für CLI-Befehle: FPM/CGI-Pfade sind nicht für Crontab geeignet
$phpBin = PHP_BINARY;
if (str_contains(basename($phpBin), 'fpm') || str_contains(basename($phpBin), 'cgi')) {
    $phpBin = 'php';
}
$cronTab = '0 3 * * * ' . $phpBin . ' ' . BASE_PATH . '/cleanup.php';

ob_start();
?>
<h1 style="margin-bottom:1.5rem"><?= e(__('admin.settings.title')) ?></h1>

<?php if (!empty($errors)): ?>
<div class="flash flash--error" role="alert">
    <ul style="margin:0;padding-left:1.25rem">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- General settings -->
<div class="card" style="margin-bottom:1.5rem">
    <h2><?= e(__('admin.settings.section_general')) ?></h2>
    <form method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_general">

        <div class="form-row" style="flex-wrap:wrap;gap:1rem">
            <div class="form-group" style="flex:1;min-width:200px">
                <label for="site_name"><?= e(__('admin.settings.site_name')) ?></label>
                <input type="text" id="site_name" name="site_name"
                       value="<?= e($s['site_name'] ?? 'Zahltag') ?>" maxlength="100">
            </div>
            <div class="form-group" style="flex:0 0 auto">
                <label for="primary_color"><?= e(__('admin.settings.primary_color')) ?></label>
                <input type="color" id="primary_color" name="primary_color"
                       value="<?= e($s['primary_color'] ?? '#2563eb') ?>"
                       style="height:2.5rem;width:80px;padding:.25rem">
            </div>
        </div>

        <div class="form-group">
            <label for="site_logo"><?= e(__('admin.settings.site_logo')) ?></label>
            <?php if ($logoPath && file_exists(BASE_PATH . '/' . $logoPath)): ?>
            <div style="margin-bottom:.5rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                <img src="<?= e(base_url($logoPath)) ?>" alt="Logo" style="height:40px;max-width:200px">
                <form method="post" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_logo">
                    <button type="submit" class="btn btn--ghost btn--sm"
                            onclick="return confirm('Logo wirklich entfernen?')">
                        <i class="fa-solid fa-trash" aria-hidden="true"></i> Logo entfernen
                    </button>
                </form>
            </div>
            <?php endif; ?>
            <input type="file" id="site_logo" name="site_logo" accept="image/png,image/jpeg">
        </div>

        <div class="form-row" style="flex-wrap:wrap;gap:1rem">
            <div class="form-group" style="flex:1;min-width:150px">
                <label for="default_currency"><?= e(__('admin.settings.default_currency')) ?></label>
                <select id="default_currency" name="default_currency">
                    <?php foreach ($currencies as $cur): ?>
                    <option value="<?= e($cur) ?>"<?= ($s['default_currency'] ?? 'EUR') === $cur ? ' selected' : '' ?>>
                        <?= e($cur) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;min-width:150px">
                <label for="max_receipt_size_mb"><?= e(__('admin.settings.max_receipt_size')) ?></label>
                <input type="number" id="max_receipt_size_mb" name="max_receipt_size_mb"
                       value="<?= (int)($s['max_receipt_size_mb'] ?? 5) ?>" min="1" max="50">
            </div>
            <div class="form-group" style="flex:1;min-width:150px">
                <label for="cleanup_days"><?= e(__('admin.settings.cleanup_days')) ?></label>
                <input type="number" id="cleanup_days" name="cleanup_days"
                       value="<?= (int)($s['cleanup_days'] ?? 90) ?>" min="0">
            </div>
        </div>

        <p class="form-hint" style="margin-bottom:.5rem;font-weight:600"><?= e(__('admin.settings.pdf_margins')) ?></p>
        <p class="form-hint" style="margin-bottom:.75rem"><?= e(__('admin.settings.pdf_margins_hint')) ?></p>
        <div style="display:flex;gap:1rem;flex-wrap:wrap">
            <div class="form-group" style="flex:1;min-width:120px">
                <label for="pdf_margin_top"><?= e(__('admin.settings.pdf_margin_top')) ?></label>
                <input type="number" id="pdf_margin_top" name="pdf_margin_top"
                       value="<?= e($s['pdf_margin_top'] ?? '1.0') ?>" step="0.1" min="0" max="5">
            </div>
            <div class="form-group" style="flex:1;min-width:120px">
                <label for="pdf_margin_right"><?= e(__('admin.settings.pdf_margin_right')) ?></label>
                <input type="number" id="pdf_margin_right" name="pdf_margin_right"
                       value="<?= e($s['pdf_margin_right'] ?? '1.0') ?>" step="0.1" min="0" max="5">
            </div>
            <div class="form-group" style="flex:1;min-width:120px">
                <label for="pdf_margin_bottom"><?= e(__('admin.settings.pdf_margin_bottom')) ?></label>
                <input type="number" id="pdf_margin_bottom" name="pdf_margin_bottom"
                       value="<?= e($s['pdf_margin_bottom'] ?? '1.0') ?>" step="0.1" min="0" max="5">
            </div>
            <div class="form-group" style="flex:1;min-width:120px">
                <label for="pdf_margin_left"><?= e(__('admin.settings.pdf_margin_left')) ?></label>
                <input type="number" id="pdf_margin_left" name="pdf_margin_left"
                       value="<?= e($s['pdf_margin_left'] ?? '2.5') ?>" step="0.1" min="0" max="5">
            </div>
        </div>

        <label class="check-row" style="margin-bottom:1rem">
            <input type="checkbox" name="multi_currency_enabled" value="1"
                   <?= ($s['multi_currency_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
            <span><?= e(__('admin.settings.multi_currency')) ?></span>
        </label>
        <p class="form-hint" style="margin-top:-.5rem;margin-bottom:1rem"><?= e(__('admin.settings.multi_currency_hint')) ?></p>

        <div class="form-group">
            <label for="impressum_text"><?= e(__('admin.settings.impressum_text')) ?></label>
            <textarea id="impressum_text" name="impressum_text" rows="5"
                      style="font-family:monospace;font-size:.875rem"><?= e($s['impressum_text'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
            <label for="datenschutz_text"><?= e(__('admin.settings.datenschutz_text')) ?></label>
            <textarea id="datenschutz_text" name="datenschutz_text" rows="5"
                      style="font-family:monospace;font-size:.875rem"><?= e($s['datenschutz_text'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn btn--primary"><?= e(__('common.save')) ?></button>
    </form>
</div>

<!-- Email settings -->
<div class="card">
    <h2><?= e(__('admin.settings.section_email')) ?></h2>
    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_email">

        <div class="form-group">
            <label for="email_method"><?= e(__('admin.settings.email_method')) ?></label>
            <select id="email_method" name="email_method" onchange="toggleEmailMethod(this.value)">
                <option value="smtp"<?= ($em['method'] ?? 'smtp') === 'smtp' ? ' selected' : '' ?>>SMTP</option>
                <option value="sendmail"<?= ($em['method'] ?? '') === 'sendmail' ? ' selected' : '' ?>>Sendmail</option>
                <option value="brevo"<?= ($em['method'] ?? '') === 'brevo' ? ' selected' : '' ?>>Brevo API</option>
            </select>
        </div>

        <div id="smtp-fields"<?= ($em['method'] ?? 'smtp') === 'brevo' ? ' class="hidden"' : '' ?>>
            <div class="form-row" style="flex-wrap:wrap;gap:1rem">
                <div class="form-group" style="flex:2;min-width:200px">
                    <label for="smtp_host"><?= e(__('admin.settings.email_smtp_host')) ?></label>
                    <input type="text" id="smtp_host" name="smtp_host"
                           value="<?= e($em['smtp_host'] ?? '') ?>" placeholder="mail.example.com">
                </div>
                <div class="form-group" style="flex:0 0 120px">
                    <label for="smtp_port"><?= e(__('admin.settings.email_smtp_port')) ?></label>
                    <input type="number" id="smtp_port" name="smtp_port"
                           value="<?= (int)($em['smtp_port'] ?? 587) ?>" min="1" max="65535">
                </div>
                <div class="form-group" style="flex:0 0 120px">
                    <label for="smtp_enc"><?= e(__('admin.settings.email_smtp_encryption')) ?></label>
                    <select id="smtp_enc" name="smtp_enc">
                        <option value="tls"<?= ($em['smtp_encryption'] ?? 'tls') === 'tls' ? ' selected' : '' ?>>STARTTLS</option>
                        <option value="ssl"<?= ($em['smtp_encryption'] ?? '') === 'ssl' ? ' selected' : '' ?>>SSL/TLS</option>
                        <option value="none"<?= ($em['smtp_encryption'] ?? '') === 'none' ? ' selected' : '' ?>>None</option>
                    </select>
                </div>
            </div>
            <div class="form-row" style="flex-wrap:wrap;gap:1rem">
                <div class="form-group" style="flex:1;min-width:200px">
                    <label for="smtp_user"><?= e(__('admin.settings.email_smtp_user')) ?></label>
                    <input type="text" id="smtp_user" name="smtp_user"
                           value="<?= e($em['smtp_user'] ?? '') ?>" autocomplete="username">
                </div>
                <div class="form-group" style="flex:1;min-width:200px">
                    <label for="smtp_pass"><?= e(__('admin.settings.email_smtp_pass')) ?></label>
                    <input type="password" id="smtp_pass" name="smtp_pass"
                           value="<?= e($em['smtp_pass'] ?? '') ?>" autocomplete="current-password">
                </div>
            </div>
        </div>

        <div id="brevo-fields"<?= ($em['method'] ?? 'smtp') !== 'brevo' ? ' class="hidden"' : '' ?>>
            <div class="form-group">
                <label for="brevo_api_key"><?= e(__('admin.settings.email_brevo_key')) ?></label>
                <input type="text" id="brevo_api_key" name="brevo_api_key"
                       value="<?= e($em['brevo_api_key'] ?? '') ?>">
            </div>
        </div>

        <div class="form-row" style="flex-wrap:wrap;gap:1rem">
            <div class="form-group" style="flex:1;min-width:200px">
                <label for="from_email"><?= e(__('admin.settings.email_from')) ?></label>
                <input type="email" id="from_email" name="from_email"
                       value="<?= e($em['from_email'] ?? '') ?>">
            </div>
            <div class="form-group" style="flex:1;min-width:200px">
                <label for="from_name"><?= e(__('admin.settings.email_from_name')) ?></label>
                <input type="text" id="from_name" name="from_name"
                       value="<?= e($em['from_name'] ?? 'Zahltag') ?>" maxlength="100">
            </div>
        </div>

        <div class="form-row" style="align-items:flex-end;gap:1rem;flex-wrap:wrap">
            <button type="submit" class="btn btn--primary"><?= e(__('common.save')) ?></button>
        </div>
    </form>

    <!-- Test email -->
    <form method="post" novalidate style="margin-top:1.5rem;padding-top:1.5rem;border-top:1px solid var(--color-border)">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="test_mail">
        <div class="form-row" style="align-items:flex-end;gap:1rem;flex-wrap:wrap">
            <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0">
                <label for="test_mail_to"><?= e(__('admin.settings.email_test_to')) ?></label>
                <input type="email" id="test_mail_to" name="test_mail_to"
                       value="" placeholder="test@example.com">
            </div>
            <button type="submit" class="btn btn--secondary">
                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> <?= e(__('admin.settings.email_test')) ?>
            </button>
        </div>
    </form>
</div>

<!-- Cron-Job -->
<div class="card">
    <h2><?= e(__('admin.settings.section_cron')) ?></h2>
    <p class="text-muted" style="font-size:.875rem;margin-bottom:1rem"><?= e(__('admin.settings.cron_section_hint')) ?></p>

    <div class="form-group">
        <label><?= e(__('admin.settings.cron_tab_label')) ?></label>
        <p class="text-muted" style="font-size:.8rem;margin-bottom:.5rem"><?= e(__('admin.settings.cron_tab_hint')) ?></p>
        <div class="copy-field">
            <input type="text" id="cron-tab" value="<?= e($cronTab) ?>" readonly style="font-family:monospace;font-size:.85rem">
            <button type="button" class="btn btn--secondary btn--sm" data-copy-target="#cron-tab">
                <?= e(__('common.copy')) ?>
            </button>
        </div>
        <p class="text-muted" style="font-size:.75rem;margin-top:.375rem">
            <?= e(__('admin.settings.cron_tab_php_hint', ['php' => $phpBin])) ?>
        </p>
    </div>

    <div class="form-group" style="margin-top:1.25rem">
        <label><?= e(__('admin.settings.cron_url_label')) ?></label>
        <p class="text-muted" style="font-size:.8rem;margin-bottom:.5rem"><?= e(__('admin.settings.cron_url_hint')) ?></p>
        <div class="copy-field">
            <input type="text" id="cron-url" value="<?= e($cronUrl) ?>" readonly>
            <button type="button" class="btn btn--secondary btn--sm" data-copy-target="#cron-url">
                <?= e(__('common.copy')) ?>
            </button>
        </div>
    </div>

    <p class="text-muted" style="font-size:.875rem;margin-top:1.25rem">
        <?= e(__('admin.settings.cron_last_run')) ?>:
        <?php if ($lastCronRun && ($ts = strtotime($lastCronRun)) !== false): ?>
            <strong><?= e(date('d.m.Y H:i', $ts)) ?></strong>
        <?php else: ?>
            <strong><?= e(__('admin.settings.cron_never')) ?></strong>
        <?php endif; ?>
    </p>

    <form method="post" style="margin-top:1rem">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="run_cleanup">
        <button type="submit" class="btn btn--ghost">
            <i class="fa-solid fa-rotate" aria-hidden="true"></i> <?= e(__('admin.settings.cron_run_now')) ?>
        </button>
    </form>
</div>

<script>
function toggleEmailMethod(v) {
    document.getElementById('smtp-fields').classList.toggle('hidden', v === 'brevo');
    document.getElementById('brevo-fields').classList.toggle('hidden', v !== 'brevo');
}
</script>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
