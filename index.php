<?php
declare(strict_types=1);

define('BASE_PATH', __DIR__);

// Autoloader
require_once BASE_PATH . '/vendor/autoload.php';

// Core includes
require_once BASE_PATH . '/includes/translation.php';

// Error handling
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    error_log("Zahltag PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}");
    return true;
});

set_exception_handler(function (Throwable $e): void {
    error_log('Zahltag Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (file_exists(BASE_PATH . '/templates/errors/500.php')) {
        require BASE_PATH . '/templates/errors/500.php';
    } else {
        echo '<h1>500 – Internal Server Error</h1>';
    }
    exit;
});

// Check if installed
$configPath  = BASE_PATH . '/config.php';
$isInstalled = file_exists($configPath);

if ($isInstalled) {
    require_once BASE_PATH . '/includes/db.php';
    require_once BASE_PATH . '/includes/functions.php';
    require_once BASE_PATH . '/includes/auth.php';
}

// Session
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Language detection
$lang = detect_language();
Translator::init($lang);

// Routing
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestUri = '/' . trim($requestUri, '/');
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Strip base path prefix for subdirectory deployments (e.g. /zahltag/group/... → /group/...)
if ($isInstalled) {
    $bp = base_path();
    if ($bp !== '' && str_starts_with($requestUri, $bp)) {
        $requestUri = substr($requestUri, strlen($bp));
    }
    if ($requestUri === '' || $requestUri[0] !== '/') {
        $requestUri = '/' . $requestUri;
    }
}

// Redirect to install if not installed
if (!$isInstalled) {
    // base_path() isn't available yet (functions.php not loaded), derive from server info
    $installBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $installPath = $installBase . '/install';
    if ($requestUri !== '/install' && $requestUri !== $installPath) {
        header('Location: ' . $installPath);
        exit;
    }
    require BASE_PATH . '/pages/install.php';
    exit;
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 0');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(self), microphone=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");

// Routes
$routes = [
    '/'                                                 => ['page' => 'landing'],
    '/install'                                          => ['page' => 'install_redirect'],
    '/create'                                           => ['page' => 'group_create'],
    '/admin'                                            => ['page' => 'admin_login'],
    '/admin/logout'                                     => ['page' => 'admin_logout'],
    '/admin/dashboard'                                  => ['page' => 'admin_dashboard'],
    '/admin/settings'                                   => ['page' => 'admin_settings'],
    '/admin/profile'                                    => ['page' => 'admin_profile'],
    '/admin/password'                                   => ['page' => 'admin_password'],
    '/admin/totp'                                       => ['page' => 'admin_totp'],
    '/admin/passkeys'                                   => ['page' => 'admin_passkeys'],
    '/admin/passkeys-auth'                              => ['page' => 'admin_passkeys_auth'],
    '/impressum'                                        => ['page' => 'impressum'],
    '/datenschutz'                                      => ['page' => 'datenschutz'],
    '/hilfe'                                             => ['page' => 'hilfe'],
    '/cron/cleanup'                                     => ['page' => 'cron_cleanup'],
];

// Dynamic route matching
$page       = null;
$params     = [];

// Check static routes first
if (isset($routes[$requestUri])) {
    $page = $routes[$requestUri]['page'];
} else {
    // Dynamic routes with token/id
    $patterns = [
        '#^/group/([a-f0-9]{64})$#'                            => ['page' => 'group_view',        'keys' => ['share_token']],
        '#^/group/([a-f0-9]{64})/expense/add$#'               => ['page' => 'expense_add',        'keys' => ['share_token']],
        '#^/group/([a-f0-9]{64})/expense/(\d+)/edit$#'        => ['page' => 'expense_edit',       'keys' => ['share_token', 'expense_id']],
        '#^/group/([a-f0-9]{64})/expense/(\d+)/delete$#'      => ['page' => 'expense_delete',     'keys' => ['share_token', 'expense_id']],
        '#^/group/([a-f0-9]{64})/settle$#'                    => ['page' => 'settle',             'keys' => ['share_token']],
        '#^/group/([a-f0-9]{64})/payment-data$#'              => ['page' => 'payment_data',       'keys' => ['share_token']],
        '#^/group/([a-f0-9]{64})/mark-paid$#'                 => ['page' => 'mark_paid',          'keys' => ['share_token']],
        '#^/group/([a-f0-9]{64})/confirm-payment$#'           => ['page' => 'confirm_payment',    'keys' => ['share_token']],
        '#^/manage/([a-f0-9]{64})$#'                          => ['page' => 'group_manage',       'keys' => ['admin_token']],
        '#^/manage/([a-f0-9]{64})/settings$#'                 => ['page' => 'group_settings',     'keys' => ['admin_token']],
        '#^/manage/([a-f0-9]{64})/members$#'                  => ['page' => 'group_members',      'keys' => ['admin_token']],
        '#^/manage/([a-f0-9]{64})/export/pdf$#'               => ['page' => 'export_pdf',         'keys' => ['admin_token']],
        '#^/manage/([a-f0-9]{64})/export/csv$#'               => ['page' => 'export_csv',         'keys' => ['admin_token']],
        '#^/manage/([a-f0-9]{64})/send-invite$#'              => ['page' => 'send_invite',        'keys' => ['admin_token']],
        '#^/api/receipt/(\d+)$#'                              => ['page' => 'api_receipt',        'keys' => ['expense_id']],
    ];

    foreach ($patterns as $pattern => $config) {
        if (preg_match($pattern, $requestUri, $matches)) {
            $page = $config['page'];
            foreach ($config['keys'] as $i => $key) {
                $params[$key] = $matches[$i + 1];
            }
            break;
        }
    }
}

if ($page === null) {
    http_response_code(404);
    if (file_exists(BASE_PATH . '/templates/errors/404.php')) {
        require BASE_PATH . '/templates/errors/404.php';
    } else {
        echo '<h1>404</h1><p>' . e(__('common.not_found')) . '</p>';
    }
    exit;
}

// Dispatch to page handler
$pageFile = BASE_PATH . '/pages/' . $page . '.php';
if (!file_exists($pageFile)) {
    http_response_code(404);
    echo '<h1>404</h1>';
    exit;
}

require $pageFile;
