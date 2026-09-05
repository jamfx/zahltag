<?php
declare(strict_types=1);

/*
 * Cron-Job-Endpunkt: Bereinigung abgelaufener Gruppen
 *
 * HTTP-Aufruf:  GET /cron/cleanup?token=SECRET
 * CLI-Aufruf:   php index.php /cron/cleanup  (nicht empfohlen auf Shared Hosting)
 *
 * Den Token findest du in Admin → Einstellungen → Cron-Job.
 * Empfehlung: täglich einmal aufrufen lassen.
 */

$cronToken = setting('cron_token', '');
$provided  = $_GET['token'] ?? '';

if ($cronToken === '' || !hash_equals($cronToken, $provided)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden — invalid or missing token']);
    exit;
}

$ts     = date('Y-m-d H:i:s');
$status = 'ok';
$error  = null;

try {
    cleanup_archived_groups();
    setting_set('last_cron_run', $ts);
} catch (Throwable $e) {
    $status = 'error';
    $error  = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode(array_filter([
    'status' => $status,
    'ts'     => $ts,
    'error'  => $error,
]));
