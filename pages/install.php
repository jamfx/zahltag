<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

// Block access if already installed
if (file_exists(BASE_PATH . '/config.php')) {
    http_response_code(403);
    die('Already installed. Remove config.php to reinstall.');
}

require_once BASE_PATH . '/includes/translation.php';

// Minimal translation loader for install (no DB yet – JSON only)
function install_translate(string $key, array $params = []): string
{
    static $strings = null;
    if ($strings === null) {
        $lang = 'en';
        if (!empty($_GET['lang']) && preg_match('/^[a-z]{2}$/', $_GET['lang'])) {
            $lang = $_GET['lang'];
        }
        $file = BASE_PATH . '/languages/' . $lang . '.json';
        if (!file_exists($file)) {
            $file = BASE_PATH . '/languages/de.json';
        }
        $data = json_decode(file_get_contents($file) ?: '{}', true);
        $strings = [];
        array_walk_recursive($data, function ($v, $k) use (&$strings, $data) {});
        $strings = flatten_array($data);
    }
    $value = $strings[$key] ?? $key;
    foreach ($params as $p => $r) {
        $value = str_replace('{' . $p . '}', (string)$r, $value);
    }
    return $value;
}

function flatten_array(array $array, string $prefix = ''): array
{
    $result = [];
    foreach ($array as $key => $value) {
        $fullKey = $prefix ? $prefix . '.' . $key : (string)$key;
        if (is_array($value)) {
            $result += flatten_array($value, $fullKey);
        } else {
            $result[$fullKey] = (string)$value;
        }
    }
    return $result;
}

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$t = 'install_translate';

$errors   = [];
$success  = false;
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'db_host'        => trim($_POST['db_host'] ?? 'localhost'),
        'db_name'        => trim($_POST['db_name'] ?? ''),
        'db_user'        => trim($_POST['db_user'] ?? ''),
        'db_pass'        => $_POST['db_pass'] ?? '',
        'admin_username' => trim($_POST['admin_username'] ?? ''),
        'admin_password' => $_POST['admin_password'] ?? '',
        'admin_password2'=> $_POST['admin_password2'] ?? '',
        'base_url'       => rtrim(trim($_POST['base_url'] ?? ''), '/'),
    ];

    // Validation
    if (empty($formData['db_name'])) {
        $errors[] = $t('install.error.db_name_required');
    }
    if (empty($formData['db_user'])) {
        $errors[] = $t('install.error.db_user_required');
    }
    if (empty($formData['admin_username'])) {
        $errors[] = $t('install.error.admin_username_required');
    }
    if (strlen($formData['admin_password']) < 8) {
        $errors[] = $t('install.error.password_too_short');
    }
    if ($formData['admin_password'] !== $formData['admin_password2']) {
        $errors[] = $t('install.error.passwords_mismatch');
    }

    if (empty($errors)) {
        try {
            // Test DB connection
            $dsn = sprintf('mysql:host=%s;charset=utf8mb4', $formData['db_host']);
            $pdo = new PDO($dsn, $formData['db_user'], $formData['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // Create database if it doesn't exist
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $formData['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('USE `' . $formData['db_name'] . '`');

            // Execute schema
            $schema = file_get_contents(BASE_PATH . '/db/schema.sql');
            // Strip -- line comments before splitting (a comment before CREATE TABLE
            // would otherwise make the whole chunk start with '--' and get dropped)
            $schema = preg_replace('/--[^\n]*/u', '', $schema);
            $statements = array_filter(
                array_map('trim', explode(';', $schema)),
                fn($s) => $s !== ''
            );
            foreach ($statements as $stmt) {
                $pdo->exec($stmt);
            }

            // Insert default site_settings
            $defaults = [
                'site_name'              => 'Zahltag',
                'default_currency'       => 'EUR',
                'multi_currency_enabled' => '0',
                'max_receipt_size_mb'    => '5',
                'cleanup_days'           => '90',
                'primary_color'          => '#2d6a4f',
                'site_logo'              => '',
                'impressum_text'         => '',
                'datenschutz_text'       => '',
                'pdf_margin_top'         => '1.0',
                'pdf_margin_right'       => '1.0',
                'pdf_margin_bottom'      => '1.0',
                'pdf_margin_left'        => '2.5',
            ];
            $stmtSetting = $pdo->prepare(
                'INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES (?, ?)'
            );
            foreach ($defaults as $key => $value) {
                $stmtSetting->execute([$key, $value]);
            }

            // Create admin account
            $passwordHash = password_hash($formData['admin_password'], PASSWORD_BCRYPT);
            $stmtAdmin = $pdo->prepare(
                'INSERT INTO admin (username, password_hash) VALUES (?, ?)'
            );
            $stmtAdmin->execute([$formData['admin_username'], $passwordHash]);

            // Generate config.php
            $appSecret = bin2hex(random_bytes(32));
            $configContent = sprintf(
                "<?php\n// Zahltag – Konfiguration\n// Automatisch generiert am %s\n// NICHT in die Versionskontrolle einchecken!\n\nreturn [\n    'db' => [\n        'host'     => %s,\n        'name'     => %s,\n        'user'     => %s,\n        'password' => %s,\n        'charset'  => 'utf8mb4',\n    ],\n    'app' => [\n        'secret'   => %s,\n        'debug'    => false,\n        'base_url' => %s,\n    ],\n];\n",
                date('Y-m-d H:i:s'),
                var_export($formData['db_host'], true),
                var_export($formData['db_name'], true),
                var_export($formData['db_user'], true),
                var_export($formData['db_pass'], true),
                var_export($appSecret, true),
                var_export($formData['base_url'], true)
            );

            file_put_contents(BASE_PATH . '/config.php', $configContent);
            chmod(BASE_PATH . '/config.php', 0600);

            $success = true;

        } catch (PDOException $e) {
            $errors[] = $t('install.error.db_connection') . ': ' . e($e->getMessage());
        } catch (Throwable $e) {
            $errors[] = $t('install.error.general') . ': ' . e($e->getMessage());
        }
    }
}

$currentLang = !empty($_GET['lang']) && preg_match('/^[a-z]{2}$/', $_GET['lang']) ? $_GET['lang'] : 'en';
?>
<!DOCTYPE html>
<html lang="<?= e($currentLang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($t('install.title')) ?> – Zahltag</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: #f0f4f0; color: #1a1a1a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.card { background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.1); padding: 2rem; width: 100%; max-width: 520px; }
h1 { font-size: 1.5rem; margin-bottom: 0.25rem; color: #2d6a4f; }
.subtitle { color: #666; margin-bottom: 1.5rem; font-size: 0.95rem; }
.lang-switch { text-align: right; margin-bottom: 1rem; font-size: 0.85rem; }
.lang-switch a { color: #2d6a4f; text-decoration: none; margin-left: 0.5rem; }
label { display: block; font-weight: 600; margin-bottom: 0.25rem; font-size: 0.9rem; }
input[type=text], input[type=password], input[type=url] { width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; margin-bottom: 1rem; }
input:focus { outline: 2px solid #2d6a4f; border-color: transparent; }
.section-title { font-size: 0.8rem; text-transform: uppercase; letter-spacing: .05em; color: #888; margin: 1.25rem 0 0.75rem; border-top: 1px solid #eee; padding-top: 1rem; }
button { width: 100%; padding: 0.75rem; background: #2d6a4f; color: #fff; border: none; border-radius: 4px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem; }
button:hover { background: #245a41; }
.error-list { background: #fee2e2; border: 1px solid #fca5a5; border-radius: 4px; padding: 0.75rem 1rem; margin-bottom: 1rem; color: #991b1b; font-size: 0.9rem; }
.error-list li { margin-left: 1.25rem; margin-top: 0.25rem; }
.success { background: #dcfce7; border: 1px solid #86efac; border-radius: 4px; padding: 1rem; color: #166534; }
.success a { color: #166534; font-weight: 600; }
small { color: #666; font-size: 0.8rem; display: block; margin-top: -0.75rem; margin-bottom: 0.75rem; }
</style>
</head>
<body>
<div class="card">
    <div class="lang-switch">
        <a href="?lang=de">Deutsch</a>
        <a href="?lang=en">English</a>
    </div>
    <h1>Zahltag</h1>
    <p class="subtitle"><?= e($t('install.subtitle')) ?></p>

    <?php if ($success): ?>
        <?php $adminUrl = rtrim($formData['base_url'] ?? '', '/') . '/admin'; ?>
        <div class="success">
            <strong><?= e($t('install.success.title')) ?></strong><br>
            <?= e($t('install.success.text')) ?><br><br>
            <a href="<?= e($adminUrl) ?>"><?= e($t('install.success.go_to_admin')) ?></a>
        </div>
    <?php else: ?>

    <?php if (!empty($errors)): ?>
        <div class="error-list">
            <strong><?= e($t('install.error.title')) ?></strong>
            <ul>
                <?php foreach ($errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <p class="section-title"><?= e($t('install.section.database')) ?></p>

        <label for="db_host"><?= e($t('install.field.db_host')) ?></label>
        <input type="text" id="db_host" name="db_host" value="<?= e($formData['db_host'] ?? 'localhost') ?>" required>

        <label for="db_name"><?= e($t('install.field.db_name')) ?></label>
        <input type="text" id="db_name" name="db_name" value="<?= e($formData['db_name'] ?? '') ?>" required>

        <label for="db_user"><?= e($t('install.field.db_user')) ?></label>
        <input type="text" id="db_user" name="db_user" value="<?= e($formData['db_user'] ?? '') ?>" autocomplete="off" required>

        <label for="db_pass"><?= e($t('install.field.db_pass')) ?></label>
        <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">

        <p class="section-title"><?= e($t('install.section.app')) ?></p>

        <label for="base_url"><?= e($t('install.field.base_url')) ?></label>
        <?php
        $detectedBase = $formData['base_url']
            ?? ((!empty($_SERVER['HTTPS']) ? 'https' : 'http')
                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/'));
        ?>
        <input type="url" id="base_url" name="base_url" value="<?= e($detectedBase) ?>" required>
        <small><?= e($t('install.field.base_url_hint')) ?></small>

        <p class="section-title"><?= e($t('install.section.admin')) ?></p>

        <label for="admin_username"><?= e($t('install.field.admin_username')) ?></label>
        <input type="text" id="admin_username" name="admin_username" value="<?= e($formData['admin_username'] ?? '') ?>" autocomplete="off" required>

        <label for="admin_password"><?= e($t('install.field.admin_password')) ?></label>
        <input type="password" id="admin_password" name="admin_password" autocomplete="new-password" required>
        <small><?= e($t('install.field.admin_password_hint')) ?></small>

        <label for="admin_password2"><?= e($t('install.field.admin_password_confirm')) ?></label>
        <input type="password" id="admin_password2" name="admin_password2" autocomplete="new-password" required>

        <button type="submit"><?= e($t('install.submit')) ?></button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
