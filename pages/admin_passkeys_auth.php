<?php
declare(strict_types=1);

// JSON-only endpoint: handles passkey login during the 2FA step.
// Caller must have $_SESSION['admin_totp_pending_id'] set.

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

if (empty($_SESSION['admin_totp_pending_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'no pending session']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';

if (!hash_equals($_SESSION['csrf_token'] ?? '', $input['_csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'csrf']);
    exit;
}

use lbuchs\WebAuthn\WebAuthn;

$adminId  = (int)$_SESSION['admin_totp_pending_id'];
$siteName = setting('site_name', 'Zahltag');
$rpId     = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');

function _wpauth_b64d(string $data): string
{
    $data = strtr($data, '-_', '+/');
    return base64_decode($data . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

// ─── auth_challenge ───────────────────────────────────────────────────────────
if ($action === 'auth_challenge') {
    try {
        // Verify at least one passkey is registered before starting
        $stmt = db()->prepare('SELECT COUNT(*) FROM admin_passkeys WHERE admin_id = ?');
        $stmt->execute([$adminId]);
        if ((int)$stmt->fetchColumn() === 0) {
            http_response_code(400);
            echo json_encode(['error' => __('admin.passkeys.no_passkeys')]);
            exit;
        }

        // Empty allowCredentials → discoverable credential flow.
        // The browser shows all stored passkeys for this RP (including extension-based
        // managers like Vaultwarden/Bitwarden) without needing a specific credential ID.
        $wa   = new WebAuthn($siteName, $rpId, null, true);
        $args = $wa->getGetArgs([], 60, true, true, true, true, true, 'preferred');
        $_SESSION['webauthn_auth_challenge'] = $wa->getChallenge();

        echo json_encode($args);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ─── auth_complete ────────────────────────────────────────────────────────────
if ($action === 'auth_complete') {
    try {
        $credIdBin = _wpauth_b64d($input['id'] ?? '');
        $credIdB64 = base64_encode($credIdBin);

        $stmt = db()->prepare(
            'SELECT * FROM admin_passkeys WHERE admin_id = ? AND credential_id = ? LIMIT 1'
        );
        $stmt->execute([$adminId, $credIdB64]);
        $passkey = $stmt->fetch() ?: null;

        if (!$passkey) {
            http_response_code(400);
            echo json_encode(['error' => __('admin.passkeys.error_not_found')]);
            exit;
        }

        $challenge = $_SESSION['webauthn_auth_challenge'] ?? null;
        if (!$challenge) {
            http_response_code(400);
            echo json_encode(['error' => __('admin.passkeys.error_no_challenge')]);
            exit;
        }

        $clientDataJSON    = _wpauth_b64d($input['clientDataJSON']    ?? '');
        $authenticatorData = _wpauth_b64d($input['authenticatorData'] ?? '');
        $signature         = _wpauth_b64d($input['signature']         ?? '');

        $wa = new WebAuthn($siteName, $rpId, null, true);
        $wa->processGet(
            $clientDataJSON,
            $authenticatorData,
            $signature,
            $passkey['public_key'],
            $challenge,
            (int)$passkey['sign_counter'],
            false
        );
        $newCounter = $wa->getSignatureCounter() ?? (int)$passkey['sign_counter'];

        db()->prepare('UPDATE admin_passkeys SET sign_counter = ? WHERE id = ?')
             ->execute([$newCounter, $passkey['id']]);

        $stmt2 = db()->prepare('SELECT * FROM admin WHERE id = ? LIMIT 1');
        $stmt2->execute([$adminId]);
        $admin = $stmt2->fetch();

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        admin_login_clear_block($ip);
        unset($_SESSION['admin_totp_pending_id'], $_SESSION['webauthn_auth_challenge']);
        $_SESSION['admin_id']       = $adminId;
        $_SESSION['admin_username'] = $admin['username'] ?? '';
        session_regenerate_id(true);

        echo json_encode(['ok' => true, 'redirect' => base_url('admin/dashboard')]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
