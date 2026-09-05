<?php
declare(strict_types=1);

if (!empty($_SESSION['admin_id'])) {
    redirect('/admin/dashboard');
}

$ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$isBlocked = admin_login_check_block($ip);

$totpPending = !empty($_SESSION['admin_totp_pending_id'])
               ? (int)$_SESSION['admin_totp_pending_id']
               : null;

$errors = [];
$step   = $totpPending ? 'totp' : 'credentials';

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isBlocked) {
    verify_csrf();

    if ($step === 'credentials') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password']      ?? '';

        $admin = null;
        try {
            $stmt = db()->prepare('SELECT * FROM admin WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $admin = $stmt->fetch() ?: null;
        } catch (Throwable) {}

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            admin_login_record_attempt($ip);
            $errors[] = __('admin.login.error_credentials');
        } else {
            $hasTOTP     = !empty($admin['totp_secret']);
            $hasPasskeys = false;
            try {
                $stmtPk = db()->prepare('SELECT COUNT(*) FROM admin_passkeys WHERE admin_id = ?');
                $stmtPk->execute([(int)$admin['id']]);
                $hasPasskeys = (int)$stmtPk->fetchColumn() > 0;
            } catch (Throwable) {}

            if ($hasTOTP || $hasPasskeys) {
                $_SESSION['admin_totp_pending_id'] = (int)$admin['id'];
                session_regenerate_id(true);
                redirect('/admin');
            } else {
                admin_login_clear_block($ip);
                $_SESSION['admin_id']       = (int)$admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                session_regenerate_id(true);
                redirect('/admin/dashboard');
            }
        }

    } elseif ($step === 'totp') {
        $subAction = $_POST['action'] ?? 'totp';

        if ($subAction === 'recovery_code') {
            $rawCode = strtoupper(str_replace(' ', '', trim($_POST['recovery_code'] ?? '')));

            $admin = null;
            try {
                $stmt = db()->prepare('SELECT * FROM admin WHERE id = ? LIMIT 1');
                $stmt->execute([$totpPending]);
                $admin = $stmt->fetch() ?: null;
            } catch (Throwable) {}

            $valid = false;
            if ($admin) {
                try {
                    $stmtCodes = db()->prepare(
                        'SELECT * FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL'
                    );
                    $stmtCodes->execute([$totpPending]);
                    foreach ($stmtCodes->fetchAll() as $row) {
                        if (password_verify($rawCode, $row['code_hash'])) {
                            db()->prepare(
                                'UPDATE admin_recovery_codes SET used_at = NOW() WHERE id = ?'
                            )->execute([$row['id']]);
                            $valid = true;
                            break;
                        }
                    }
                } catch (Throwable) {}
            }

            if ($valid) {
                admin_login_clear_block($ip);
                unset($_SESSION['admin_totp_pending_id']);
                $_SESSION['admin_id']       = (int)$admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                session_regenerate_id(true);
                redirect('/admin/dashboard');
            } else {
                admin_login_record_attempt($ip);
                $errors[] = __('admin.passkeys.error_invalid_recovery');
            }

        } else {
            // TOTP code
            $code = trim($_POST['totp_code'] ?? '');

            $admin = null;
            try {
                $stmt = db()->prepare('SELECT * FROM admin WHERE id = ? LIMIT 1');
                $stmt->execute([$totpPending]);
                $admin = $stmt->fetch() ?: null;
            } catch (Throwable) {}

            $valid = false;
            if ($admin && $admin['totp_secret']) {
                $matched = totp_verify($admin['totp_secret'], $code);
                if ($matched !== false) {
                    $lastKey = 'admin_' . $admin['id'] . '_totp_ts';
                    $lastTs  = (int)setting($lastKey, 0);
                    if ($matched > $lastTs) {
                        try {
                            $stmtTs = db()->prepare(
                                'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE setting_value = ?'
                            );
                            $stmtTs->execute([$lastKey, (string)$matched, (string)$matched]);
                        } catch (Throwable) {}
                        $valid = true;
                    } else {
                        $errors[] = __('admin.totp.error_already_used');
                    }
                } else {
                    $errors[] = __('admin.totp.error_invalid');
                }
            }

            if ($valid) {
                admin_login_clear_block($ip);
                unset($_SESSION['admin_totp_pending_id']);
                $_SESSION['admin_id']       = (int)$admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                session_regenerate_id(true);
                redirect('/admin/dashboard');
            } else {
                admin_login_record_attempt($ip);
            }
        }
    }
}

// Allow cancelling 2FA step
if (isset($_GET['cancel']) && $totpPending) {
    unset($_SESSION['admin_totp_pending_id']);
    redirect('/admin');
}

// ─── Determine available 2FA methods ─────────────────────────────────────────
$hasTOTP     = false;
$hasPasskeys = false;
$hasRecovery = false;

if ($totpPending) {
    try {
        $stmt = db()->prepare('SELECT totp_secret FROM admin WHERE id = ? LIMIT 1');
        $stmt->execute([$totpPending]);
        $row     = $stmt->fetch();
        $hasTOTP = !empty($row['totp_secret']);
    } catch (Throwable) {}

    try {
        $stmt        = db()->prepare('SELECT COUNT(*) FROM admin_passkeys WHERE admin_id = ?');
        $stmt->execute([$totpPending]);
        $hasPasskeys = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable) {}

    try {
        $stmt        = db()->prepare(
            'SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$totpPending]);
        $hasRecovery = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable) {}
}

// ─── Render ───────────────────────────────────────────────────────────────────
$pageTitle = __('admin.login.title');
$navLinks  = [];

ob_start();
?>
<div style="max-width:420px;margin:3rem auto">
    <h1 style="margin-bottom:1.5rem"><?= e(__('admin.login.title')) ?></h1>

    <?php if ($isBlocked): ?>
    <div class="flash flash--error" role="alert">
        <?= e(__('admin.login.error_locked', ['minutes' => 15])) ?>
    </div>

    <?php elseif (!empty($errors)): ?>
    <div class="flash flash--error" role="alert">
        <?php foreach ($errors as $err): ?>
            <div><?= e($err) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$isBlocked): ?>
    <div class="card">

    <?php if ($step === 'credentials'): ?>
        <form method="post" novalidate autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="form-group">
                <label for="username"><?= e(__('admin.login.username')) ?></label>
                <input type="text" id="username" name="username"
                       autocomplete="username" required autofocus>
            </div>
            <div class="form-group">
                <label for="password"><?= e(__('admin.login.password')) ?></label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn--primary btn--full"><?= e(__('admin.login.submit')) ?></button>
        </form>

    <?php else: // 2FA step ?>

        <?php if ($hasTOTP): ?>
        <p style="margin-bottom:1rem;font-size:.9rem" class="text-muted"><?= e(__('admin.login.totp_hint')) ?></p>
        <form method="post" novalidate autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="totp">
            <div class="form-group">
                <label for="totp_code"><?= e(__('admin.login.totp_code')) ?></label>
                <input type="text" id="totp_code" name="totp_code"
                       inputmode="numeric" pattern="\d{6}" maxlength="6"
                       autocomplete="one-time-code" required autofocus>
            </div>
            <button type="submit" class="btn btn--primary btn--full"><?= e(__('admin.login.submit')) ?></button>
        </form>
        <?php endif; ?>

        <?php if ($hasPasskeys): ?>
        <div style="<?= $hasTOTP ? 'margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--color-border)' : '' ?>">
            <?php if ($hasTOTP): ?>
            <p class="text-muted" style="font-size:.85rem;margin-bottom:.5rem"><?= e(__('admin.passkeys.login_or_passkey')) ?></p>
            <?php else: ?>
            <p class="text-muted" style="font-size:.9rem;margin-bottom:.75rem"><?= e(__('admin.passkeys.login_hint')) ?></p>
            <?php endif; ?>
            <button type="button" id="btn-passkey-auth" class="btn btn--secondary btn--full">
                <i class="fa-solid fa-key" aria-hidden="true"></i>
                <?= e(__('admin.passkeys.login_with_passkey')) ?>
            </button>
            <p id="passkey-auth-error" style="display:none;margin-top:.5rem;font-size:.875rem;color:var(--color-danger,#dc2626)" role="alert"></p>
        </div>
        <?php endif; ?>

        <?php if ($hasRecovery): ?>
        <details style="margin-top:1.25rem">
            <summary style="cursor:pointer;font-size:.85rem;color:var(--color-text-muted,#6b7280);user-select:none">
                <?= e(__('admin.passkeys.login_use_recovery')) ?>
            </summary>
            <form method="post" novalidate autocomplete="off" style="margin-top:.75rem">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="recovery_code">
                <div class="form-group">
                    <label for="recovery_code"><?= e(__('admin.passkeys.recovery_code_label')) ?></label>
                    <input type="text" id="recovery_code" name="recovery_code"
                           autocomplete="off" placeholder="XXXXX-XXXXX" maxlength="11"
                           style="text-transform:uppercase;letter-spacing:.12em">
                </div>
                <button type="submit" class="btn btn--ghost"><?= e(__('admin.login.submit')) ?></button>
            </form>
        </details>
        <?php endif; ?>

        <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--color-border)">
            <a href="<?= e(base_url('admin')) ?>?cancel=1" class="btn btn--ghost btn--sm">
                <?= e(__('common.cancel')) ?>
            </a>
        </div>

    <?php endif; // step ?>
    </div>
    <?php endif; // !$isBlocked ?>
</div>

<?php if ($step === 'totp' && $hasPasskeys): ?>
<script>
(function () {
    'use strict';

    var btn    = document.getElementById('btn-passkey-auth');
    var errEl  = document.getElementById('passkey-auth-error');
    var CSRF   = <?= json_encode(csrf_token()) ?>;
    var AUTH   = <?= json_encode(base_url('admin/passkeys-auth')) ?>;

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

    function api(action, extra) {
        return fetch(AUTH, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(Object.assign({action: action, _csrf: CSRF}, extra || {}))
        }).then(function (r) { return r.json(); });
    }

    if (!btn) return;

    if (!window.PublicKeyCredential) {
        btn.disabled = true;
        if (errEl) {
            errEl.textContent = <?= json_encode(__('admin.passkeys.not_supported')) ?>;
            errEl.style.display = '';
        }
        return;
    }

    btn.addEventListener('click', function () {
        if (errEl) errEl.style.display = 'none';
        btn.disabled = true;

        api('auth_challenge').then(function (opts) {
            if (opts.error) { btn.disabled = false; throw new Error(opts.error); }

            opts.publicKey.challenge = b64d(opts.publicKey.challenge);
            if (opts.publicKey.allowCredentials) {
                opts.publicKey.allowCredentials = opts.publicKey.allowCredentials.map(function (c) {
                    return Object.assign({}, c, {id: b64d(c.id)});
                });
            }
            return navigator.credentials.get(opts);

        }).then(function (assertion) {
            if (!assertion) throw new Error('no assertion');
            return api('auth_complete', {
                id:                assertion.id,
                clientDataJSON:    b64e(assertion.response.clientDataJSON),
                authenticatorData: b64e(assertion.response.authenticatorData),
                signature:         b64e(assertion.response.signature),
                userHandle:        assertion.response.userHandle
                                   ? b64e(assertion.response.userHandle) : null
            });

        }).then(function (res) {
            if (res && res.ok && res.redirect) {
                window.location.href = res.redirect;
            } else {
                btn.disabled = false;
                throw new Error((res && res.error) || 'Authentication failed');
            }

        }).catch(function (err) {
            btn.disabled = false;
            if (err && err.name !== 'NotAllowedError' && err !== null) {
                if (errEl) { errEl.textContent = err.message || String(err); errEl.style.display = ''; }
            }
        });
    });
}());
</script>
<?php endif; ?>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
