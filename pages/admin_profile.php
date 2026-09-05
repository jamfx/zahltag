<?php
declare(strict_types=1);

require_admin();

use lbuchs\WebAuthn\WebAuthn;

$adminId   = (int)$_SESSION['admin_id'];
$adminUser = $_SESSION['admin_username'] ?? 'admin';
$siteName  = setting('site_name', 'Zahltag');
$rpId      = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');

function _wap_b64d(string $data): string
{
    $data = strtr($data, '-_', '+/');
    return base64_decode($data . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

$admin = null;
try {
    $stmt = db()->prepare('SELECT * FROM admin WHERE id = ? LIMIT 1');
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch() ?: null;
} catch (Throwable) {}

if (!$admin) redirect('/admin/dashboard');

$totpEnabled = !empty($admin['totp_secret']);
$isJson      = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

// ─── Pending recovery confirmation ───────────────────────────────────────────
if (!$isJson && !empty($_SESSION['admin_recovery_pending_codes'])) {
    $pendingCodes = $_SESSION['admin_recovery_pending_codes'];
    $pendingType  = $_SESSION['admin_recovery_pending_type'] ?? 'passkey';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_recovery') {
        verify_csrf();
        unset(
            $_SESSION['admin_recovery_pending_codes'],
            $_SESSION['admin_recovery_pending_type'],
            $_SESSION['admin_recovery_pending_totp'],
            $_SESSION['admin_recovery_pending_passkey']
        );
        redirect('/admin/profile');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_recovery') {
        verify_csrf();
        try {
            if ($pendingType === 'passkey') {
                $pk = $_SESSION['admin_recovery_pending_passkey'] ?? [];
                db()->prepare(
                    'INSERT INTO admin_passkeys (admin_id, credential_id, public_key, sign_counter, device_name)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$adminId, $pk['credential_id'], $pk['public_key'], (int)$pk['sign_counter'], $pk['device_name']]);
            } elseif ($pendingType === 'totp') {
                $secret = $_SESSION['admin_recovery_pending_totp'] ?? '';
                db()->prepare('UPDATE admin SET totp_secret = ? WHERE id = ?')
                     ->execute([$secret, $adminId]);
            }

            db()->prepare('DELETE FROM admin_recovery_codes WHERE admin_id = ?')->execute([$adminId]);
            $stmtCode = db()->prepare('INSERT INTO admin_recovery_codes (admin_id, code_hash) VALUES (?, ?)');
            foreach ($pendingCodes as $code) {
                $stmtCode->execute([$adminId, password_hash($code, PASSWORD_BCRYPT)]);
            }
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/admin/profile');
        }

        unset(
            $_SESSION['admin_recovery_pending_codes'],
            $_SESSION['admin_recovery_pending_type'],
            $_SESSION['admin_recovery_pending_totp'],
            $_SESSION['admin_recovery_pending_passkey']
        );
        flash('success', __('admin.passkeys.setup_complete'));
        redirect('/admin/profile');
    }

    $pageTitle = __('admin.passkeys.recovery_confirm_title');
    $navLinks  = [
        ['url' => base_url('admin/dashboard'), 'label' => __('admin.dashboard.title'), 'icon' => 'fa-solid fa-gauge',       'active' => false],
        ['url' => base_url('admin/settings'),  'label' => __('admin.settings.title'),  'icon' => 'fa-solid fa-sliders',     'active' => false],
        ['url' => base_url('admin/profile'),   'label' => __('admin.nav.profile'),     'icon' => 'fa-solid fa-circle-user', 'active' => true],
        ['type' => 'logout', 'label' => __('admin.login.logout'), 'icon' => 'fa-solid fa-right-from-bracket'],
    ];
    ob_start();
    ?>
    <h1 style="margin-bottom:.5rem"><?= e(__('admin.passkeys.recovery_confirm_title')) ?></h1>
    <p class="text-muted" style="margin-bottom:1.25rem;max-width:520px"><?= e(__('admin.passkeys.recovery_confirm_hint')) ?></p>

    <div class="flash flash--warning" role="alert" style="max-width:520px;margin-bottom:1.25rem">
        <?= e(__('admin.passkeys.recovery_confirm_warning')) ?>
    </div>

    <div class="card" style="max-width:520px">
        <h2 style="margin-bottom:.75rem;font-size:1rem"><?= e(__('admin.passkeys.recovery_show_title')) ?></h2>
        <div id="pending-codes-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.75rem">
            <?php foreach ($pendingCodes as $rc): ?>
            <code style="background:var(--color-bg-alt);padding:.4rem .6rem;border-radius:var(--radius);font-size:.9rem;letter-spacing:.12em"><?= e($rc) ?></code>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem">
            <button type="button" class="btn btn--secondary btn--sm" id="btn-copy-pending">
                <i class="fa-regular fa-copy" aria-hidden="true"></i>
                <?= e(__('admin.passkeys.recovery_copy')) ?>
            </button>
            <button type="button" class="btn btn--ghost btn--sm" id="btn-dl-pending">
                <i class="fa-solid fa-download" aria-hidden="true"></i>
                <?= e(__('admin.passkeys.recovery_download')) ?>
            </button>
        </div>

        <form method="post" id="confirm-recovery-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="confirm_recovery">
            <label style="display:flex;gap:.75rem;align-items:flex-start;cursor:pointer;margin-bottom:1rem;line-height:1.5">
                <input type="checkbox" id="codes-saved" name="codes_saved" value="1"
                       style="margin-top:.2rem;flex-shrink:0" required>
                <span><?= e(__('admin.passkeys.recovery_confirm_checkbox')) ?></span>
            </label>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                <button type="submit" id="btn-finish" class="btn btn--primary" disabled>
                    <?= e(__('admin.passkeys.recovery_confirm_submit')) ?>
                </button>
                <button type="submit" name="action" value="cancel_recovery"
                        class="btn btn--ghost" formnovalidate>
                    <?= e(__('common.cancel')) ?>
                </button>
            </div>
        </form>
    </div>

    <script>
    (function () {
        var codes     = <?= json_encode($pendingCodes) ?>;
        var copiedLbl = <?= json_encode(__('common.copied')) ?>;

        document.getElementById('codes-saved').addEventListener('change', function () {
            document.getElementById('btn-finish').disabled = !this.checked;
        });

        var btnCopy = document.getElementById('btn-copy-pending');
        if (btnCopy) {
            btnCopy.addEventListener('click', function () {
                navigator.clipboard.writeText(codes.join('\n')).then(function () {
                    var orig = btnCopy.textContent;
                    btnCopy.textContent = copiedLbl;
                    setTimeout(function () { btnCopy.textContent = orig; }, 1800);
                });
            });
        }

        var btnDl = document.getElementById('btn-dl-pending');
        if (btnDl) {
            btnDl.addEventListener('click', function () {
                var blob = new Blob([codes.join('\n')], { type: 'text/plain' });
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href = url; a.download = 'zahltag-recovery-codes.txt'; a.click();
                setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
            });
        }
    }());
    </script>
    <?php
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

// ─── JSON API (Passkey registration) ─────────────────────────────────────────
if ($isJson && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $input['_csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'csrf']);
        exit;
    }

    if ($action === 'register_challenge') {
        try {
            $wa = new WebAuthn($siteName, $rpId, null, true);

            $stmt = db()->prepare('SELECT credential_id FROM admin_passkeys WHERE admin_id = ?');
            $stmt->execute([$adminId]);
            $excludeIds = array_map('base64_decode', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $args = $wa->getCreateArgs(
                random_bytes(16),
                $adminUser,
                $adminUser,
                60,
                true,
                'preferred',
                null,
                $excludeIds
            );
            $_SESSION['webauthn_challenge'] = $wa->getChallenge();
            echo json_encode($args);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;

    } elseif ($action === 'register_complete') {
        try {
            $name = mb_substr(trim($input['deviceName'] ?? ''), 0, 100);
            if ($name === '') $name = 'Passkey';

            $clientDataJSON = _wap_b64d($input['clientDataJSON']    ?? '');
            $attestation    = _wap_b64d($input['attestationObject'] ?? '');
            $challenge      = $_SESSION['webauthn_challenge'] ?? null;
            if (!$challenge) throw new RuntimeException(__('admin.passkeys.error_no_challenge'));

            $wa   = new WebAuthn($siteName, $rpId, null, true);
            $data = $wa->processCreate($clientDataJSON, $attestation, $challenge, false, false);

            $credId = base64_encode($data->credentialId);
            $chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $codes  = [];
            for ($i = 0; $i < 10; $i++) {
                $raw = '';
                for ($j = 0; $j < 10; $j++) {
                    $raw .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
            }
            unset($_SESSION['webauthn_challenge']);
            $_SESSION['admin_recovery_pending_type']    = 'passkey';
            $_SESSION['admin_recovery_pending_passkey'] = [
                'credential_id' => $credId,
                'public_key'    => $data->credentialPublicKey,
                'sign_counter'  => (int)$data->signatureCounter,
                'device_name'   => $name,
            ];
            $_SESSION['admin_recovery_pending_codes'] = $codes;
            echo json_encode(['ok' => true, 'redirect' => base_url('admin/profile')]);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
}

// ─── Form POST ────────────────────────────────────────────────────────────────
$passwordErrors = [];
$totpErrors     = [];
$pendingSec     = $_SESSION['admin_totp_setup_secret'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // ── Password ──────────────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $admin['password_hash'])) {
            $passwordErrors[] = __('admin.password.error_current');
        } elseif (strlen($new) < 8) {
            $passwordErrors[] = __('admin.password.error_short');
        } elseif ($new !== $confirm) {
            $passwordErrors[] = __('admin.password.error_mismatch');
        } else {
            try {
                db()->prepare('UPDATE admin SET password_hash = ? WHERE id = ?')
                     ->execute([password_hash($new, PASSWORD_BCRYPT), $adminId]);
            } catch (Throwable $e) {
                $passwordErrors[] = $e->getMessage();
            }
            if (empty($passwordErrors)) {
                flash('success', __('admin.password.success'));
                redirect('/admin/profile#password');
            }
        }

    // ── TOTP ──────────────────────────────────────────────────────────────────
    } elseif ($action === 'begin_setup' && !$totpEnabled) {
        $secret = totp_generate_secret();
        $_SESSION['admin_totp_setup_secret'] = $secret;
        $pendingSec = $secret;
        redirect('/admin/profile');

    } elseif ($action === 'confirm_setup' && !$totpEnabled && $pendingSec) {
        $code = trim($_POST['totp_code'] ?? '');
        if (totp_verify($pendingSec, $code) !== false) {
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $codes = [];
            for ($i = 0; $i < 10; $i++) {
                $raw = '';
                for ($j = 0; $j < 10; $j++) {
                    $raw .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
            }
            unset($_SESSION['admin_totp_setup_secret']);
            $_SESSION['admin_recovery_pending_codes'] = $codes;
            $_SESSION['admin_recovery_pending_type']  = 'totp';
            $_SESSION['admin_recovery_pending_totp']  = $pendingSec;
            redirect('/admin/profile');
        } else {
            $totpErrors[] = __('admin.totp.error_invalid');
        }

    } elseif ($action === 'disable' && $totpEnabled) {
        $code = trim($_POST['totp_code'] ?? '');
        if (totp_verify($admin['totp_secret'], $code) !== false) {
            try {
                db()->prepare('UPDATE admin SET totp_secret = NULL WHERE id = ?')->execute([$adminId]);
                db()->prepare("DELETE FROM site_settings WHERE setting_key = ?")
                     ->execute(['admin_' . $adminId . '_totp_ts']);
                $stmtPk = db()->prepare('SELECT COUNT(*) FROM admin_passkeys WHERE admin_id = ?');
                $stmtPk->execute([$adminId]);
                if ((int)$stmtPk->fetchColumn() === 0) {
                    db()->prepare('DELETE FROM admin_recovery_codes WHERE admin_id = ?')->execute([$adminId]);
                }
            } catch (Throwable $e) {
                $totpErrors[] = $e->getMessage();
            }
            if (empty($totpErrors)) {
                flash('success', __('admin.totp.disable_success'));
                redirect('/admin/profile#totp');
            }
        } else {
            $totpErrors[] = __('admin.totp.error_invalid');
        }

    } elseif ($action === 'cancel_setup') {
        unset($_SESSION['admin_totp_setup_secret']);
        redirect('/admin/profile');

    // ── Passkeys ──────────────────────────────────────────────────────────────
    } elseif ($action === 'delete_passkey') {
        $pkId = (int)($_POST['passkey_id'] ?? 0);
        db()->prepare('DELETE FROM admin_passkeys WHERE id = ? AND admin_id = ?')
             ->execute([$pkId, $adminId]);

        $stmtCheck = db()->prepare(
            'SELECT (SELECT COUNT(*) FROM admin_passkeys WHERE admin_id = ?) AS pk_count,
                    (SELECT totp_secret FROM admin WHERE id = ?) AS totp_secret'
        );
        $stmtCheck->execute([$adminId, $adminId]);
        $remaining = $stmtCheck->fetch();
        if ((int)$remaining['pk_count'] === 0 && empty($remaining['totp_secret'])) {
            db()->prepare('DELETE FROM admin_recovery_codes WHERE admin_id = ?')->execute([$adminId]);
        }

        flash('success', __('admin.passkeys.deleted'));
        redirect('/admin/profile#passkeys');

    } elseif ($action === 'generate_recovery') {
        // Require at least one active 2FA method
        $stmtHas = db()->prepare('SELECT COUNT(*) FROM admin_passkeys WHERE admin_id = ?');
        $stmtHas->execute([$adminId]);
        $has2fa = $totpEnabled || (int)$stmtHas->fetchColumn() > 0;
        if (!$has2fa) {
            redirect('/admin/profile#passkeys');
        }

        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        db()->prepare('DELETE FROM admin_recovery_codes WHERE admin_id = ?')->execute([$adminId]);
        $stmt = db()->prepare('INSERT INTO admin_recovery_codes (admin_id, code_hash) VALUES (?, ?)');
        foreach ($codes as $code) {
            $stmt->execute([$adminId, password_hash($code, PASSWORD_BCRYPT)]);
        }
        $_SESSION['admin_recovery_show'] = $codes;
        flash('success', __('admin.passkeys.recovery_generated'));
        redirect('/admin/profile#passkeys');
    }

    // Reload admin after any DB change
    try {
        $stmt = db()->prepare('SELECT * FROM admin WHERE id = ? LIMIT 1');
        $stmt->execute([$adminId]);
        $admin       = $stmt->fetch() ?: $admin;
        $totpEnabled = !empty($admin['totp_secret']);
    } catch (Throwable) {}
}

// ─── Prepare data for rendering ───────────────────────────────────────────────
$pendingSec = $_SESSION['admin_totp_setup_secret'] ?? null;
$pendingQr  = '';
if ($pendingSec && !$totpEnabled) {
    $pendingQr = totp_qr_base64($pendingSec, $siteName, $adminUser);
}

$passkeys = [];
try {
    $stmt = db()->prepare('SELECT * FROM admin_passkeys WHERE admin_id = ? ORDER BY created_at DESC');
    $stmt->execute([$adminId]);
    $passkeys = $stmt->fetchAll();
} catch (Throwable) {}

$recoveryTotal = 0;
$recoveryUsed  = 0;
try {
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS total, SUM(used_at IS NOT NULL) AS used FROM admin_recovery_codes WHERE admin_id = ?'
    );
    $stmt->execute([$adminId]);
    $row           = $stmt->fetch();
    $recoveryTotal = (int)($row['total'] ?? 0);
    $recoveryUsed  = (int)($row['used']  ?? 0);
} catch (Throwable) {}

$newCodes = $_SESSION['admin_recovery_show'] ?? null;
unset($_SESSION['admin_recovery_show']);

$pageTitle = __('admin.nav.profile');
$navLinks  = [
    ['url' => base_url('admin/dashboard'), 'label' => __('admin.dashboard.title'), 'icon' => 'fa-solid fa-gauge',       'active' => false],
    ['url' => base_url('admin/settings'),  'label' => __('admin.settings.title'),  'icon' => 'fa-solid fa-sliders',     'active' => false],
    ['url' => base_url('admin/profile'),   'label' => __('admin.nav.profile'),     'icon' => 'fa-solid fa-circle-user', 'active' => true],
    ['type' => 'logout', 'label' => __('admin.login.logout'), 'icon' => 'fa-solid fa-right-from-bracket'],
];

ob_start();
?>
<h1 style="margin-bottom:2rem"><?= e(__('admin.nav.profile')) ?></h1>

<!-- ─── Passwort ─────────────────────────────────────────────────────────────── -->
<section id="password" style="margin-bottom:2.5rem;scroll-margin-top:5rem">
    <h2 style="margin-bottom:1rem"><?= e(__('admin.password.title')) ?></h2>

    <?php if (!empty($passwordErrors)): ?>
    <div class="flash flash--error" role="alert" style="max-width:480px">
        <?php foreach ($passwordErrors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card" style="max-width:480px">
        <form method="post" novalidate autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label for="current_password"><?= e(__('admin.password.current')) ?></label>
                <input type="password" id="current_password" name="current_password"
                       autocomplete="current-password" required>
            </div>
            <div class="form-group">
                <label for="new_password"><?= e(__('admin.password.new')) ?></label>
                <input type="password" id="new_password" name="new_password"
                       autocomplete="new-password" minlength="8" required>
                <small class="form-hint"><?= e(__('install.field.admin_password_hint')) ?></small>
            </div>
            <div class="form-group">
                <label for="confirm_password"><?= e(__('admin.password.confirm')) ?></label>
                <input type="password" id="confirm_password" name="confirm_password"
                       autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn--primary"><?= e(__('admin.password.submit')) ?></button>
        </form>
    </div>
</section>

<!-- ─── 2FA / TOTP ───────────────────────────────────────────────────────────── -->
<section id="totp" style="margin-bottom:2.5rem;scroll-margin-top:5rem">
    <h2 style="margin-bottom:1rem"><?= e(__('admin.totp.title')) ?></h2>

    <?php if (!empty($totpErrors)): ?>
    <div class="flash flash--error" role="alert" style="max-width:520px">
        <?php foreach ($totpErrors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card" style="max-width:520px">
        <?php if ($totpEnabled): ?>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem">
            <span class="badge badge--success" style="font-size:.9rem"><?= e(__('admin.totp.status_enabled')) ?></span>
        </div>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="disable">
            <div class="form-group">
                <label for="totp_disable_code"><?= e(__('admin.totp.disable_confirm_code')) ?></label>
                <input type="text" id="totp_disable_code" name="totp_code"
                       inputmode="numeric" pattern="\d{6}" maxlength="6"
                       autocomplete="one-time-code" required>
            </div>
            <button type="submit" class="btn btn--danger"><?= e(__('admin.totp.disable')) ?></button>
        </form>

        <?php elseif ($pendingSec): ?>
        <p style="margin-bottom:1rem"><?= e(__('admin.totp.scan_hint')) ?></p>
        <?php if ($pendingQr): ?>
        <div style="text-align:center;margin-bottom:1rem">
            <img src="<?= e($pendingQr) ?>" alt="TOTP QR" style="width:200px;height:200px">
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label><?= e(__('admin.totp.secret_hint')) ?></label>
            <code style="display:block;padding:.5rem;background:var(--color-bg-alt);border-radius:var(--radius);letter-spacing:.15em;word-break:break-all">
                <?= e($pendingSec) ?>
            </code>
        </div>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="confirm_setup">
            <div class="form-group">
                <label for="totp_confirm_code"><?= e(__('admin.totp.confirm_code')) ?></label>
                <input type="text" id="totp_confirm_code" name="totp_code"
                       inputmode="numeric" pattern="\d{6}" maxlength="6"
                       autocomplete="one-time-code" required autofocus>
            </div>
            <div style="display:flex;gap:.5rem">
                <button type="submit" class="btn btn--primary"><?= e(__('admin.totp.confirm_submit')) ?></button>
                <button type="submit" form="form-cancel-totp" class="btn btn--ghost"><?= e(__('common.cancel')) ?></button>
            </div>
        </form>
        <form method="post" id="form-cancel-totp" style="display:none">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="cancel_setup">
        </form>

        <?php else: ?>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem">
            <span class="badge badge--muted" style="font-size:.9rem"><?= e(__('admin.totp.status_disabled')) ?></span>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="begin_setup">
            <button type="submit" class="btn btn--primary"><?= e(__('admin.totp.enable')) ?></button>
        </form>
        <?php endif; ?>
    </div>
</section>

<!-- ─── Passkeys & Notfallcodes ──────────────────────────────────────────────── -->
<section id="passkeys" style="scroll-margin-top:5rem">
    <h2 style="margin-bottom:1rem"><?= e(__('admin.passkeys.title')) ?></h2>

    <?php if ($newCodes): ?>
    <div class="card" style="max-width:520px;margin-bottom:1.5rem;border:2px solid var(--color-warning,#f59e0b)">
        <h3 style="font-size:1rem;margin-bottom:.5rem"><?= e(__('admin.passkeys.recovery_show_title')) ?></h3>
        <p class="text-muted" style="margin-bottom:1rem;font-size:.875rem"><?= e(__('admin.passkeys.recovery_show_hint')) ?></p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.75rem">
            <?php foreach ($newCodes as $rc): ?>
            <code style="background:var(--color-bg-alt);padding:.4rem .6rem;border-radius:var(--radius);font-size:.9rem;letter-spacing:.12em"><?= e($rc) ?></code>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem">
            <button type="button" class="btn btn--secondary btn--sm" id="btn-copy-new">
                <i class="fa-regular fa-copy" aria-hidden="true"></i>
                <?= e(__('admin.passkeys.recovery_copy')) ?>
            </button>
            <button type="button" class="btn btn--ghost btn--sm" id="btn-dl-new">
                <i class="fa-solid fa-download" aria-hidden="true"></i>
                <?= e(__('admin.passkeys.recovery_download')) ?>
            </button>
        </div>
        <p class="text-muted" style="font-size:.8rem"><?= e(__('admin.passkeys.recovery_show_footer')) ?></p>
    </div>
    <?php endif; ?>

    <div class="card" style="max-width:520px;margin-bottom:1.5rem">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
            <h3 style="margin:0"><?= e(__('admin.passkeys.section_passkeys')) ?></h3>
            <button type="button" id="btn-add-passkey" class="btn btn--primary btn--sm">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                <?= e(__('admin.passkeys.add')) ?>
            </button>
        </div>
        <p id="passkey-unsupported" style="display:none" class="text-muted"><?= e(__('admin.passkeys.not_supported')) ?></p>
        <p id="passkey-error" style="display:none;color:var(--color-danger,#dc2626)" class="text-muted" role="alert"></p>
        <?php if (empty($passkeys)): ?>
        <p class="text-muted"><?= e(__('admin.passkeys.none')) ?></p>
        <?php else: ?>
        <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5rem">
            <?php foreach ($passkeys as $pk): ?>
            <li style="display:flex;align-items:center;justify-content:space-between;padding:.6rem .75rem;border-radius:var(--radius);background:var(--color-bg-alt)">
                <span>
                    <i class="fa-solid fa-key" aria-hidden="true" style="opacity:.45;margin-right:.4rem"></i>
                    <strong><?= e($pk['device_name']) ?></strong>
                    <small class="text-muted" style="margin-left:.5rem"><?= e(format_date($pk['created_at'])) ?></small>
                </span>
                <form method="post" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_passkey">
                    <input type="hidden" name="passkey_id" value="<?= (int)$pk['id'] ?>">
                    <button type="submit" class="btn btn--ghost btn--sm btn--danger"
                            data-confirm="<?= e(__('admin.passkeys.delete_confirm', ['name' => $pk['device_name']])) ?>"
                            aria-label="<?= e(__('admin.passkeys.delete_label', ['name' => $pk['device_name']])) ?>">
                        <i class="fa-solid fa-trash" aria-hidden="true"></i>
                    </button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <div id="passkey-name-modal" class="modal-backdrop" style="display:none"
         aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="pk-dialog-title">
        <div class="modal">
            <h2 id="pk-dialog-title" style="margin-bottom:.75rem"><?= e(__('admin.passkeys.name_title')) ?></h2>
            <p class="text-muted" style="margin-bottom:1rem;font-size:.875rem"><?= e(__('admin.passkeys.name_hint')) ?></p>
            <div class="form-group">
                <label for="pk-name-input"><?= e(__('admin.passkeys.name_label')) ?></label>
                <input type="text" id="pk-name-input" maxlength="100" autocomplete="off"
                       placeholder="<?= e(__('admin.passkeys.name_placeholder')) ?>">
            </div>
            <div style="display:flex;gap:.5rem;margin-top:1rem">
                <button type="button" id="pk-name-ok" class="btn btn--primary"><?= e(__('common.confirm')) ?></button>
                <button type="button" id="pk-name-cancel" class="btn btn--ghost"><?= e(__('common.cancel')) ?></button>
            </div>
        </div>
    </div>

    <div class="card" style="max-width:520px">
        <h3 style="margin-bottom:.5rem"><?= e(__('admin.passkeys.section_recovery')) ?></h3>
        <?php
        $has2fa = $totpEnabled || !empty($passkeys);
        if ($has2fa):
            if ($recoveryTotal === 0): ?>
        <p class="text-muted" style="margin-bottom:1rem"><?= e(__('admin.passkeys.recovery_none')) ?></p>
        <?php else:
                $remaining = $recoveryTotal - $recoveryUsed;
        ?>
        <p class="text-muted" style="margin-bottom:1rem">
            <?= e(__('admin.passkeys.recovery_status', ['remaining' => $remaining, 'total' => $recoveryTotal])) ?>
        </p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="generate_recovery">
            <button type="submit" class="btn btn--secondary"
                <?= $recoveryTotal > 0 ? 'data-confirm="' . e(__('admin.passkeys.recovery_regen_confirm')) . '"' : '' ?>>
                <?= e($recoveryTotal === 0 ? __('admin.passkeys.recovery_generate') : __('admin.passkeys.recovery_regenerate')) ?>
            </button>
        </form>
        <p class="text-muted" style="font-size:.85rem;margin-top:.75rem"><?= e(__('admin.passkeys.recovery_hint')) ?></p>
        <?php else: ?>
        <p class="text-muted"><?= e(__('admin.passkeys.recovery_no_2fa')) ?></p>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    'use strict';

    var CSRF    = <?= json_encode(csrf_token()) ?>;
    var API_URL = <?= json_encode(base_url('admin/profile')) ?>;

    function b64d(str) {
        var s = str.replace(/-/g, '+').replace(/_/g, '/');
        var p = (4 - s.length % 4) % 4;
        for (var i = 0; i < p; i++) s += '=';
        var bin = atob(s), buf = new Uint8Array(bin.length);
        for (var j = 0; j < bin.length; j++) buf[j] = bin.charCodeAt(j);
        return buf.buffer;
    }

    function b64e(buf) {
        var bytes = new Uint8Array(buf), str = '';
        for (var i = 0; i < bytes.length; i++) str += String.fromCharCode(bytes[i]);
        return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    function apiPost(action, extra) {
        return fetch(API_URL, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(Object.assign({action: action, _csrf: CSRF}, extra || {}))
        }).then(function (r) { return r.json(); });
    }

    var btnAdd  = document.getElementById('btn-add-passkey');
    var errEl   = document.getElementById('passkey-error');
    var unsupEl = document.getElementById('passkey-unsupported');
    var modal   = document.getElementById('passkey-name-modal');
    var nameIn  = document.getElementById('pk-name-input');
    var btnOk   = document.getElementById('pk-name-ok');
    var btnCncl = document.getElementById('pk-name-cancel');
    var removeTrap;

    if (!window.PublicKeyCredential) {
        if (btnAdd)  btnAdd.disabled = true;
        if (unsupEl) unsupEl.style.display = '';
    }

    function openDialog() {
        if (!modal) return;
        modal.style.display = '';
        modal.setAttribute('aria-hidden', 'false');
        if (nameIn) nameIn.value = '';
        removeTrap = trapFocus(modal.querySelector('.modal'));
        setTimeout(function () { if (nameIn) nameIn.focus(); }, 0);
    }

    function closeDialog() {
        if (!modal) return;
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
        if (removeTrap) { removeTrap(); removeTrap = null; }
        if (btnAdd) btnAdd.focus();
    }

    if (btnAdd)  btnAdd.addEventListener('click', function () { if (window.PublicKeyCredential) openDialog(); });
    if (btnCncl) btnCncl.addEventListener('click', closeDialog);
    if (modal) {
        modal.addEventListener('click', function (e) { if (e.target === modal) closeDialog(); });
        modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDialog(); });
    }
    if (btnOk) {
        btnOk.addEventListener('click', function () {
            var name = nameIn ? nameIn.value.trim() : '';
            if (!name) name = 'Passkey';
            closeDialog();
            doRegister(name);
        });
    }

    function showError(msg) {
        if (!errEl) return;
        errEl.textContent = msg;
        errEl.style.display = '';
    }

    function doRegister(deviceName) {
        if (errEl) errEl.style.display = 'none';

        apiPost('register_challenge').then(function (opts) {
            if (opts.error) { showError(opts.error); return Promise.reject(null); }
            opts.publicKey.challenge = b64d(opts.publicKey.challenge);
            opts.publicKey.user.id   = b64d(opts.publicKey.user.id);
            if (opts.publicKey.excludeCredentials) {
                opts.publicKey.excludeCredentials = opts.publicKey.excludeCredentials.map(function (c) {
                    return Object.assign({}, c, {id: b64d(c.id)});
                });
            }
            return navigator.credentials.create(opts);
        }).then(function (cred) {
            if (!cred) return Promise.reject(null);
            return apiPost('register_complete', {
                deviceName:        deviceName,
                id:                cred.id,
                clientDataJSON:    b64e(cred.response.clientDataJSON),
                attestationObject: b64e(cred.response.attestationObject)
            });
        }).then(function (res) {
            if (!res) return;
            if (res.ok) {
                window.location.href = res.redirect || window.location.href;
            } else {
                showError(res.error || 'Registration failed');
            }
        }).catch(function (err) {
            if (err && err.name && err.name !== 'NotAllowedError') {
                showError(err.message || String(err));
            }
        });
    }

    <?php if ($newCodes): ?>
    (function () {
        var codes     = <?= json_encode($newCodes) ?>;
        var copiedLbl = <?= json_encode(__('common.copied')) ?>;

        var btnCopy = document.getElementById('btn-copy-new');
        if (btnCopy) {
            btnCopy.addEventListener('click', function () {
                navigator.clipboard.writeText(codes.join('\n')).then(function () {
                    var orig = btnCopy.textContent.trim();
                    btnCopy.textContent = copiedLbl;
                    setTimeout(function () { btnCopy.innerHTML = '<i class="fa-regular fa-copy" aria-hidden="true"></i> ' + orig; }, 1800);
                });
            });
        }
        var btnDl = document.getElementById('btn-dl-new');
        if (btnDl) {
            btnDl.addEventListener('click', function () {
                var blob = new Blob([codes.join('\n')], { type: 'text/plain' });
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href = url; a.download = 'zahltag-recovery-codes.txt'; a.click();
                setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
            });
        }
    }());
    <?php endif; ?>
}());
</script>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
