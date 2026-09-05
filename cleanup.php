#!/usr/bin/env php
<?php
/**
 * Standalone cleanup script — run via cron, e.g.:
 *   0 3 * * * /usr/bin/php /path/to/app/cleanup.php
 *
 * Deletes archived groups past the retention period and empty groups
 * (no expenses) older than the configured retention period.
 */
declare(strict_types=1);

// Block any web request — CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

define('BASE_PATH', __DIR__);

$configPath = BASE_PATH . '/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "No config found at " . $configPath . "\n");
    exit(1);
}

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/functions.php';

try {
    db();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    cleanup_archived_groups();
    setting_set('last_cron_run', date('Y-m-d H:i:s'));
} catch (Throwable $e) {
    fwrite(STDERR, "Cleanup error: " . $e->getMessage() . "\n");
    exit(1);
}

printf("[%s] Cleanup done.\n", date('Y-m-d H:i:s'));
