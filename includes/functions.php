<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function setting(string $key, mixed $default = null): mixed
{
    static $cache = [];

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = ($row !== false) ? $row['setting_value'] : $default;
    } catch (Throwable) {
        $cache[$key] = $default;
    }

    return $cache[$key];
}

function setting_set(string $key, string $value): void
{
    db()->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?'
    )->execute([$key, $value, $value]);
}

function generate_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function redirect(string $url): never
{
    // Prefix root-relative paths with the app's base path (subdirectory support)
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $url = base_path() . $url;
    }
    header('Location: ' . $url);
    exit;
}

function base_path(): string
{
    static $path = null;
    if ($path === null) {
        try {
            $cfg    = require __DIR__ . '/../config.php';
            $parsed = parse_url($cfg['app']['base_url'] ?? '');
            $path   = rtrim($parsed['path'] ?? '', '/');
        } catch (Throwable) {
            $path = '';
        }
    }
    return $path;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generate_token(32);
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die(__('common.csrf_error'));
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        try {
            $cfg  = require __DIR__ . '/../config.php';
            $base = rtrim($cfg['app']['base_url'] ?? '', '/');
        } catch (Throwable) {
            $base = '';
        }
    }
    return $base . '/' . ltrim($path, '/');
}

function current_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

/**
 * Turn arbitrary text (e.g. a group name) into a plain-ASCII string that is
 * safe to use in download filenames: umlauts become ae/oe/ue/ss, other accents
 * are stripped, anything else becomes an underscore.
 */
function ascii_filename_part(string $text): string
{
    $text = strtr($text, [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
    ]);
    if (class_exists('Transliterator')) {
        $t = \Transliterator::create('Any-Latin; Latin-ASCII');
        if ($t !== null) {
            $text = $t->transliterate($text) ?: $text;
        }
    }
    if (class_exists('Normalizer')) {
        $text = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text) ?? $text;
    }
    $text = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $text) ?? '';
    $text = trim($text, '_');
    return $text !== '' ? $text : 'export';
}

function format_currency(float $amount, string $currency = 'EUR'): string
{
    $lang = $_SESSION['lang'] ?? 'de';
    $locale = ($lang === 'de') ? 'de_DE' : 'en_US';

    $fmt = new NumberFormatter($locale, NumberFormatter::CURRENCY);
    return $fmt->formatCurrency($amount, $currency);
}

function format_number(float $number, int $decimals = 2): string
{
    $lang = $_SESSION['lang'] ?? 'de';
    if ($lang === 'de') {
        return number_format($number, $decimals, ',', '.');
    }
    return number_format($number, $decimals, '.', ',');
}

function format_date(string $date): string
{
    $lang = $_SESSION['lang'] ?? 'de';
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }
    return ($lang === 'de') ? date('d.m.Y', $ts) : date('Y-m-d', $ts);
}

function get_exchange_rate(string $from, string $to): ?float
{
    if ($from === $to) {
        return 1.0;
    }
    try {
        // Check today's cache first
        $stmt = db()->prepare(
            'SELECT rate FROM exchange_rates
             WHERE base_currency = ? AND target_currency = ? AND rate_date = CURDATE()
             LIMIT 1'
        );
        $stmt->execute([$from, $to]);
        $cached = $stmt->fetchColumn();
        if ($cached !== false) {
            return (float)$cached;
        }

        // Fetch from Frankfurter API (no API key required)
        $url     = 'https://api.frankfurter.dev/v1/latest?base=' . urlencode($from) . '&symbols=' . urlencode($to);
        $context = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $json    = @file_get_contents($url, false, $context);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        $rate = $data['rates'][$to] ?? null;
        if (!$rate) {
            return null;
        }

        // Cache the result
        $stmtIns = db()->prepare(
            'INSERT IGNORE INTO exchange_rates (base_currency, target_currency, rate, rate_date)
             VALUES (?, ?, ?, CURDATE())'
        );
        $stmtIns->execute([$from, $to, $rate]);

        return (float)$rate;
    } catch (Throwable) {
        return null;
    }
}

function process_receipt_image(string $tmpPath, string $destPath): bool
{
    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }

    $mime = mime_content_type($tmpPath) ?: '';

    // PDFs: validate magic bytes, move without GD processing
    if ($mime === 'application/pdf') {
        $fh = @fopen($tmpPath, 'rb');
        if (!$fh) return false;
        $magic = fread($fh, 5);
        fclose($fh);
        if ($magic !== '%PDF-') return false;
        return move_uploaded_file($tmpPath, $destPath) || rename($tmpPath, $destPath);
    }

    if (!extension_loaded('gd')) {
        return move_uploaded_file($tmpPath, $destPath) || rename($tmpPath, $destPath);
    }
    $img  = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmpPath),
        'image/png'  => @imagecreatefrompng($tmpPath),
        'image/webp' => @imagecreatefromwebp($tmpPath),
        default      => false,
    };
    if (!$img) {
        return false;
    }

    // Correct EXIF orientation if available
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmpPath);
        $orientation = $exif['Orientation'] ?? 1;
        $img = match ((int)$orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }

    $width  = imagesx($img);
    $height = imagesy($img);
    $max    = 1920;

    if ($width > $max) {
        $newW   = $max;
        $newH   = (int)round($height * $max / $width);
        $scaled = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($scaled, $img, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($img);
        $img = $scaled;
    }

    $result = imagejpeg($img, $destPath, 85);
    imagedestroy($img);
    return $result;
}

function generate_receipt_number(int $groupId, int $memberId, string $memberName, string $expenseDate): string
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM expenses WHERE group_id = ? AND paid_by = ? AND receipt_path IS NOT NULL'
        );
        $stmt->execute([$groupId, $memberId]);
        $count = (int)$stmt->fetchColumn() + 1;
    } catch (Throwable) {
        $count = 1;
    }

    // Sanitize member name: keep letters, digits, hyphens
    $clean = preg_replace('/[^\p{L}\p{N}\-]/u', '', $memberName);
    $clean = mb_substr($clean ?: 'Member', 0, 20);

    return sprintf('%s-Beleg-%02d_%s', $clean, $count, $expenseDate);
}

function calculate_equal_splits(array $memberIds, float $totalAmount): array
{
    $count  = count($memberIds);
    if ($count === 0) return [];
    $share  = round($totalAmount / $count, 2);
    $splits = array_fill_keys($memberIds, $share);
    // Compensate rounding difference on the last member
    $diff   = round($totalAmount - array_sum($splits), 2);
    if ($diff !== 0.0) {
        $lastKey = array_key_last($splits);
        $splits[$lastKey] = round($splits[$lastKey] + $diff, 2);
    }
    return $splits;
}

function calculate_weighted_splits(array $memberWeights, float $totalAmount): array
{
    if (empty($memberWeights)) return [];
    $totalWeight = array_sum($memberWeights);
    if ($totalWeight <= 0) return calculate_equal_splits(array_keys($memberWeights), $totalAmount);
    $splits = [];
    foreach ($memberWeights as $mid => $weight) {
        $splits[$mid] = round($totalAmount * ((float)$weight / $totalWeight), 2);
    }
    $diff = round($totalAmount - array_sum($splits), 2);
    if ($diff !== 0.0) {
        $lastKey = array_key_last($splits);
        $splits[$lastKey] = round($splits[$lastKey] + $diff, 2);
    }
    return $splits;
}

function log_activity(int $groupId, ?int $memberId, string $action, array $details = []): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_log (group_id, member_id, action, details) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$groupId, $memberId, $action, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null]);

        // Real activity cancels a scheduled automatic archiving immediately.
        // (If the group is still settled/inactive, the cron job schedules it again.)
        if (in_array($action, ARCHIVE_ACTIVITY_ACTIONS, true)) {
            db()->prepare('UPDATE groups SET archive_at = NULL WHERE id = ? AND archive_at IS NOT NULL')
                ->execute([$groupId]);
        }
    } catch (Throwable) {
        // Logging must never break the application
    }
}

/** Actions that count as "activity" for the automatic archiving. */
const ARCHIVE_ACTIVITY_ACTIONS = [
    'expense_added', 'expense_edited', 'expense_deleted',
    'payment_marked', 'payment_confirmed',
    'member_joined', 'member_deactivated',
];

function parse_amount(string $input): float
{
    // Accept both comma and period as decimal separator
    $normalized = str_replace([' ', "\u{00A0}"], '', $input);
    // If comma appears after period, it's a thousands separator (e.g. 1.234,56)
    if (preg_match('/\d+\.\d{3},\d{1,2}$/', $normalized)) {
        $normalized = str_replace(['.', ','], ['', '.'], $normalized);
    } elseif (str_contains($normalized, ',')) {
        $normalized = str_replace(',', '.', str_replace('.', '', $normalized));
    }
    return (float)$normalized;
}

function validate_iban(string $iban): bool
{
    try {
        $validator = new \Iban\Validation\Validator();
        return $validator->validate(new \Iban\Validation\Iban($iban));
    } catch (Throwable) {
        return false;
    }
}

function format_iban(string $iban): string
{
    $raw = strtoupper(preg_replace('/\s+/', '', $iban));
    return implode(' ', str_split($raw, 4));
}

/**
 * Calculate balances for all members of a group.
 * Returns array keyed by member_id:
 *   [id, name, paid, owes, confirmed_paid, confirmed_received, balance]
 * Only confirmed payments are reflected in balance.
 */
function calculate_balances(int $groupId): array
{
    $balances = [];

    try {
        // All members (active and inactive) that belong to this group
        $stmtM = db()->prepare(
            'SELECT id, name FROM members WHERE group_id = ? ORDER BY name'
        );
        $stmtM->execute([$groupId]);
        foreach ($stmtM->fetchAll() as $m) {
            $balances[(int)$m['id']] = [
                'id'                 => (int)$m['id'],
                'name'               => $m['name'],
                'paid'               => 0.0,
                'owes'               => 0.0,
                'confirmed_paid'     => 0.0,
                'confirmed_received' => 0.0,
                'balance'            => 0.0,
            ];
        }

        // Sum of expenses paid by each member
        $stmtPaid = db()->prepare(
            'SELECT paid_by, SUM(amount) AS total FROM expenses
             WHERE group_id = ? GROUP BY paid_by'
        );
        $stmtPaid->execute([$groupId]);
        foreach ($stmtPaid->fetchAll() as $row) {
            $mid = (int)$row['paid_by'];
            if (isset($balances[$mid])) {
                $balances[$mid]['paid'] = (float)$row['total'];
            }
        }

        // Sum of expense_splits (what each member owes for their share)
        $stmtOwes = db()->prepare(
            'SELECT es.member_id, SUM(es.share_amount) AS total
             FROM expense_splits es
             JOIN expenses e ON e.id = es.expense_id
             WHERE e.group_id = ?
             GROUP BY es.member_id'
        );
        $stmtOwes->execute([$groupId]);
        foreach ($stmtOwes->fetchAll() as $row) {
            $mid = (int)$row['member_id'];
            if (isset($balances[$mid])) {
                $balances[$mid]['owes'] = (float)$row['total'];
            }
        }

        // Confirmed payments: adjust balances
        $stmtPay = db()->prepare(
            'SELECT from_member_id, to_member_id, amount
             FROM payments
             WHERE group_id = ? AND confirmed_by_recipient = 1'
        );
        $stmtPay->execute([$groupId]);
        foreach ($stmtPay->fetchAll() as $p) {
            $from = (int)$p['from_member_id'];
            $to   = (int)$p['to_member_id'];
            $amt  = (float)$p['amount'];
            if (isset($balances[$from])) {
                $balances[$from]['confirmed_paid'] += $amt;
            }
            if (isset($balances[$to])) {
                $balances[$to]['confirmed_received'] += $amt;
            }
        }

        foreach ($balances as &$b) {
            $b['balance'] = round(
                $b['paid'] - $b['owes'] + $b['confirmed_paid'] - $b['confirmed_received'],
                2
            );
        }
        unset($b);

    } catch (Throwable) {}

    return $balances;
}

/**
 * Minimal-payments algorithm.
 * Returns array of ['from_id', 'from_name', 'to_id', 'to_name', 'amount'].
 */
function calculate_settlements(array $balances): array
{
    $debtors   = []; // member_id => negative balance (amount they owe)
    $creditors = []; // member_id => positive balance (amount they should receive)

    foreach ($balances as $id => $b) {
        if ($b['balance'] < -0.005) {
            $debtors[$id]   = abs($b['balance']);
        } elseif ($b['balance'] > 0.005) {
            $creditors[$id] = $b['balance'];
        }
    }

    $settlements = [];

    while (!empty($debtors) && !empty($creditors)) {
        // Largest debtor
        arsort($debtors);
        $fromId   = array_key_first($debtors);
        $fromDebt = $debtors[$fromId];

        // Largest creditor
        arsort($creditors);
        $toId      = array_key_first($creditors);
        $toCredit  = $creditors[$toId];

        $amount = round(min($fromDebt, $toCredit), 2);

        if ($amount > 0) {
            $settlements[] = [
                'from_id'   => $fromId,
                'from_name' => $balances[$fromId]['name'],
                'to_id'     => $toId,
                'to_name'   => $balances[$toId]['name'],
                'amount'    => $amount,
            ];
        }

        $debtors[$fromId]   = round($fromDebt  - $amount, 2);
        $creditors[$toId]   = round($toCredit  - $amount, 2);

        if ($debtors[$fromId]   < 0.005) unset($debtors[$fromId]);
        if ($creditors[$toId]   < 0.005) unset($creditors[$toId]);
    }

    return $settlements;
}

/**
 * Generate a GiroCode/EPC QR code as a data: URI (PNG, base64).
 * Returns empty string on failure.
 * Only valid for EUR transfers.
 */
function generate_girocode_base64(string $iban, string $name, float $amount, string $reference): string
{
    try {
        $qrData = (new \SepaQr\SepaQrData())
            ->setName(mb_substr($name, 0, 70))
            ->setIban(strtoupper(preg_replace('/\s+/', '', $iban)))
            ->setAmount($amount)
            ->setRemittanceText(mb_substr($reference, 0, 140));

        return qr_data_uri((string)$qrData);
    } catch (Throwable $e) {
        error_log('GiroCode generation failed: ' . $e->getMessage());
        return '';
    }
}

/**
 * Render text as a QR code data: URI. Uses PNG when the GD extension is
 * available, otherwise falls back to SVG (no GD required; Dompdf and
 * browsers both render SVG data URIs).
 */
function qr_data_uri(string $data): string
{
    $useGd = extension_loaded('gd');
    $result = (new \Endroid\QrCode\Builder\Builder(
        writer: $useGd ? new \Endroid\QrCode\Writer\PngWriter() : new \Endroid\QrCode\Writer\SvgWriter(),
        data: $data,
        errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Medium,
        size: 200,
        margin: 4,
    ))->build();

    return $result->getDataUri();
}

// ── TOTP (RFC 6238) ───────────────────────────────────────────────────────────

function totp_generate_secret(): string
{
    return rtrim(strtr(base64_encode(random_bytes(20)), '+/', '-_'), '=');
}

function totp_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input    = strtoupper(rtrim($secret, '='));
    $output   = '';
    $v        = 0;
    $vbits    = 0;
    for ($i = 0, $len = strlen($input); $i < $len; $i++) {
        $pos = strpos($alphabet, $input[$i]);
        if ($pos === false) continue;
        $v     = ($v << 5) | $pos;
        $vbits += 5;
        if ($vbits >= 8) {
            $vbits  -= 8;
            $output .= chr(($v >> $vbits) & 0xFF);
        }
    }
    return $output;
}

function totp_code(string $secret, int $timestamp = 0): string
{
    if ($timestamp === 0) $timestamp = time();
    $counter = pack('J', (int)floor($timestamp / 30));
    $key     = totp_base32_decode($secret);
    $hash    = hash_hmac('sha1', $counter, $key, true);
    $offset  = ord($hash[19]) & 0x0F;
    $code    = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
        ((ord($hash[$offset + 3]) & 0xFF))
    ) % 1_000_000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

/**
 * Verify a TOTP code. Accepts codes ±1 window (30 s each) to allow clock drift.
 * Returns the matched timestamp (usable as replay key) or false.
 */
function totp_verify(string $secret, string $code): int|false
{
    $now = time();
    for ($i = -1; $i <= 1; $i++) {
        $ts = $now + $i * 30;
        if (hash_equals(totp_code($secret, $ts), $code)) {
            return (int)floor($ts / 30);
        }
    }
    return false;
}

function totp_qr_base64(string $secret, string $issuer, string $account): string
{
    $uri = 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=6&period=30';
    try {
        return qr_data_uri($uri);
    } catch (Throwable $e) {
        error_log('TOTP QR generation failed: ' . $e->getMessage());
        return '';
    }
}

// ── Admin login helpers ───────────────────────────────────────────────────────

function group_create_rate_limited(string $ip): bool
{
    $key  = 'gcr_' . substr(hash('sha256', $ip), 0, 16);
    $raw  = setting($key);
    if (!$raw) return false;
    $data = json_decode($raw, true);
    if (!$data || !isset($data['count'], $data['until'])) return false;
    return time() < (int)$data['until'] && (int)$data['count'] >= 10;
}

function group_create_record(string $ip): void
{
    $key  = 'gcr_' . substr(hash('sha256', $ip), 0, 16);
    $raw  = setting($key);
    $data = $raw ? (json_decode($raw, true) ?? []) : [];
    $now  = time();
    $windowStillOpen = isset($data['until']) && (int)$data['until'] > $now;
    $count  = $windowStillOpen ? (int)($data['count'] ?? 0) + 1 : 1;
    $until  = $windowStillOpen ? (int)$data['until'] : $now + 3600;
    $value  = json_encode(['count' => $count, 'until' => $until]);
    try {
        db()->prepare(
            'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?'
        )->execute([$key, $value, $value]);
    } catch (Throwable) {}
}

function admin_login_check_block(string $ip): bool
{
    $key  = 'login_block_' . substr(hash('sha256', $ip), 0, 16);
    $raw  = setting($key);
    if (!$raw) return false;
    $data = json_decode($raw, true);
    if (!$data || !isset($data['until'])) return false;
    return time() < (int)$data['until'];
}

function admin_login_record_attempt(string $ip): void
{
    $key  = 'login_block_' . substr(hash('sha256', $ip), 0, 16);
    $raw  = setting($key);
    $data = $raw ? (json_decode($raw, true) ?? []) : [];
    $attempts = ($data['attempts'] ?? 0) + 1;
    $until    = $attempts >= 5 ? time() + 900 : 0; // 15 min block after 5 attempts
    $value    = json_encode(['attempts' => $attempts, 'until' => $until]);
    try {
        $stmt = db()->prepare(
            'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = ?'
        );
        $stmt->execute([$key, $value, $value]);
        // Bust the static cache
        $ref = new ReflectionFunction('setting');
        $vars = $ref->getStaticVariables();
        // Can't bust static easily — rely on next request reading DB fresh
    } catch (Throwable) {}
}

function admin_login_clear_block(string $ip): void
{
    $key = 'login_block_' . substr(hash('sha256', $ip), 0, 16);
    try {
        db()->prepare('DELETE FROM site_settings WHERE setting_key = ?')->execute([$key]);
    } catch (Throwable) {}
}

/**
 * All retention periods in one place (configurable in Admin → Settings).
 * 0 disables the respective rule.
 */
function archive_settings(): array
{
    return [
        'inactivity_months' => max(0, (int)setting('inactivity_months', 9)),       // inactive → archive
        'warning_days'      => max(0, (int)setting('archive_warning_days', 30)),   // banner lead time
        'settled_months'    => max(0, (int)setting('settled_archive_months', 1)),  // settled → archive
        'extend_months'     => max(1, (int)setting('archive_extend_months', 1)),   // admin extension
        'cleanup_days'      => max(0, (int)setting('cleanup_days', 30)),           // archived → delete
        'empty_days'        => max(0, (int)setting('empty_group_days', 90)),       // empty group → delete
    ];
}

/** Adds groups.archive_at on existing installations (runs once, cheap check). */
function ensure_archive_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $has = db()->query("SHOW COLUMNS FROM `groups` LIKE 'archive_at'")->fetch();
        if (!$has) {
            db()->exec('ALTER TABLE `groups` ADD COLUMN `archive_at` DATETIME NULL AFTER `archived_at`');
        }
    } catch (Throwable) {}
}

/**
 * A group is "settled" when it has expenses, no unconfirmed payments are open
 * and all balances (incl. confirmed payments) are zero.
 */
function group_is_settled(int $groupId): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT (SELECT COUNT(*) FROM expenses WHERE group_id = ?) AS ex,
                    (SELECT COUNT(*) FROM payments WHERE group_id = ? AND confirmed_by_recipient = 0) AS open_pay'
        );
        $stmt->execute([$groupId, $groupId]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['ex'] === 0 || (int)$row['open_pay'] > 0) {
            return false;
        }
    } catch (Throwable) {
        return false;
    }

    foreach (calculate_balances($groupId) as $b) {
        if (abs((float)$b['balance']) >= 0.01) {
            return false;
        }
    }
    return true;
}

/**
 * Schedules and executes the automatic archiving:
 *  - settled group            → archive_at = now + settled_months
 *  - inactive for N months    → banner from (N months − warning_days), archive at N months
 *  - archive_at reached       → group is archived (then cleanup_days applies)
 *  - no longer settled/inactive (new activity) → schedule is removed
 */
function schedule_group_archiving(): void
{
    ensure_archive_schema();
    $cfg = archive_settings();

    try {
        $now = new DateTimeImmutable((string)db()->query('SELECT NOW()')->fetchColumn());

        $rows = db()->query(
            "SELECT g.id, g.archive_at,
                    GREATEST(
                        COALESCE(g.created_at, NOW()),
                        COALESCE((SELECT MAX(GREATEST(e.created_at, COALESCE(e.updated_at, e.created_at)))
                                  FROM expenses e WHERE e.group_id = g.id), g.created_at),
                        COALESCE((SELECT MAX(GREATEST(p.created_at, COALESCE(p.confirmed_at, p.created_at)))
                                  FROM payments p WHERE p.group_id = g.id), g.created_at),
                        COALESCE((SELECT MAX(m.created_at) FROM members m WHERE m.group_id = g.id), g.created_at),
                        COALESCE((SELECT MAX(a.created_at) FROM activity_log a
                                  WHERE a.group_id = g.id
                                    AND a.action IN ('expense_added','expense_edited','expense_deleted',
                                                     'payment_marked','payment_confirmed',
                                                     'member_joined','member_deactivated')), g.created_at)
                    ) AS last_activity
             FROM `groups` g
             WHERE g.archived_at IS NULL"
        )->fetchAll();

        foreach ($rows as $row) {
            $gid       = (int)$row['id'];
            $archiveAt = $row['archive_at'] ? new DateTimeImmutable($row['archive_at']) : null;

            $settled = $cfg['settled_months'] > 0 && group_is_settled($gid);

            $inactiveAt = null;
            $inWarning  = false;
            if ($cfg['inactivity_months'] > 0 && $row['last_activity']) {
                $inactiveAt = (new DateTimeImmutable($row['last_activity']))
                    ->modify('+' . $cfg['inactivity_months'] . ' months');
                $inWarning  = $now >= $inactiveAt->modify('-' . $cfg['warning_days'] . ' days');
            }

            if ($archiveAt === null) {
                if ($settled) {
                    $archiveAt = $now->modify('+' . $cfg['settled_months'] . ' months');
                } elseif ($inWarning) {
                    // Always give the full warning period, even if the cron job was down
                    $archiveAt = max($inactiveAt, $now->modify('+' . $cfg['warning_days'] . ' days'));
                }
                if ($archiveAt !== null) {
                    db()->prepare('UPDATE `groups` SET archive_at = ? WHERE id = ?')
                        ->execute([$archiveAt->format('Y-m-d H:i:s'), $gid]);
                }
                continue;
            }

            if (!$settled && !$inWarning) {
                // Condition no longer applies (new activity) → cancel
                db()->prepare('UPDATE `groups` SET archive_at = NULL WHERE id = ?')->execute([$gid]);
                continue;
            }

            if ($archiveAt <= $now) {
                db()->prepare('UPDATE `groups` SET archived_at = NOW(), archive_at = NULL WHERE id = ? AND archived_at IS NULL')
                    ->execute([$gid]);
                log_activity($gid, null, 'group_auto_archived');
            }
        }
    } catch (Throwable) {}
}

/** Group admin extends the scheduled archiving date. Returns true on success. */
function extend_group_archiving(int $groupId): bool
{
    $months = archive_settings()['extend_months'];
    try {
        $stmt = db()->prepare(
            'UPDATE `groups` SET archive_at = DATE_ADD(archive_at, INTERVAL ? MONTH)
             WHERE id = ? AND archived_at IS NULL AND archive_at IS NOT NULL'
        );
        $stmt->execute([$months, $groupId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Banner data for a group: null if no archiving is scheduled.
 * Returns ['days' => int, 'date' => 'd.m.Y'].
 */
function group_archive_notice(?array $group): ?array
{
    if (!$group || empty($group['archive_at']) || !empty($group['archived_at'])) {
        return null;
    }
    try {
        $now = new DateTimeImmutable((string)db()->query('SELECT NOW()')->fetchColumn());
        $at  = new DateTimeImmutable($group['archive_at']);
    } catch (Throwable) {
        return null;
    }
    $days = (int)ceil(($at->getTimestamp() - $now->getTimestamp()) / 86400);
    return ['days' => max(0, $days), 'date' => $at->format('d.m.Y')];
}

/**
 * Permanently deletes a group incl. receipt files and all dependent rows.
 * Payments/expenses reference members without CASCADE, so a plain
 * "DELETE FROM groups" fails for groups with payments – delete in order.
 */
function delete_group_completely(int $groupId): bool
{
    try {
        $stmtR = db()->prepare(
            'SELECT receipt_path FROM expenses WHERE group_id = ? AND receipt_path IS NOT NULL'
        );
        $stmtR->execute([$groupId]);
        foreach ($stmtR->fetchAll() as $r) {
            $filePath = BASE_PATH . '/' . ltrim($r['receipt_path'], '/');
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        $receiptDir = BASE_PATH . '/uploads/receipts/' . $groupId;
        if (is_dir($receiptDir)) {
            @rmdir($receiptDir);
        }

        db()->beginTransaction();
        db()->prepare('DELETE FROM payments WHERE group_id = ?')->execute([$groupId]);
        db()->prepare('DELETE FROM expenses WHERE group_id = ?')->execute([$groupId]);
        db()->prepare('DELETE FROM members WHERE group_id = ?')->execute([$groupId]);
        db()->prepare('DELETE FROM `groups` WHERE id = ?')->execute([$groupId]);
        db()->commit();
        return true;
    } catch (Throwable) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return false;
    }
}

/**
 * Delete archived groups older than cleanup_days, and empty groups
 * (0 expenses) created more than empty_group_days days ago.
 * Also runs the automatic archiving scheduler first.
 * Runs silently; called on admin dashboard load and via cron endpoint.
 */
function cleanup_archived_groups(): void
{
    schedule_group_archiving();

    $cfg = archive_settings();
    $days      = $cfg['cleanup_days'];
    $emptyDays = $cfg['empty_days'];

    try {
        $archivedIds = [];
        if ($days > 0) {
            $stmt = db()->prepare(
                'SELECT id FROM `groups`
                 WHERE archived_at IS NOT NULL
                   AND archived_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
            );
            $stmt->execute([$days]);
            $archivedIds = array_column($stmt->fetchAll(), 'id');
        }

        $emptyIds = [];
        if ($emptyDays > 0) {
            $stmtEmpty = db()->prepare(
                'SELECT g.id FROM `groups` g
                 WHERE g.archived_at IS NULL
                   AND g.created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND NOT EXISTS (SELECT 1 FROM expenses e WHERE e.group_id = g.id)'
            );
            $stmtEmpty->execute([$emptyDays]);
            $emptyIds = array_column($stmtEmpty->fetchAll(), 'id');
        }

        $groupIds = array_unique(array_merge($archivedIds, $emptyIds));

        foreach ($groupIds as $gid) {
            delete_group_completely((int)$gid);
        }
    } catch (Throwable) {}
}
