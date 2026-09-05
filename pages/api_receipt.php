<?php
declare(strict_types=1);

// Serve receipt images through PHP to prevent direct file access.
// Caller must be an authenticated member of the group that owns the expense.

$expenseId = (int)($params['expense_id'] ?? 0);

// Load expense without share_token from route (the token is implicit via session or not needed here)
// We need to find the expense first, then verify group membership via session
$expense = null;
try {
    $stmt = db()->prepare('SELECT * FROM expenses WHERE id = ? LIMIT 1');
    $stmt->execute([$expenseId]);
    $expense = $stmt->fetch() ?: null;
} catch (Throwable) {}

if (!$expense || !$expense['receipt_path']) {
    http_response_code(404); exit;
}

// Verify session: member must belong to the expense's group
$sessionMemberId = $_SESSION['member_id'] ?? null;
$sessionGroupId  = $_SESSION['group_id']  ?? null;

if (!$sessionMemberId || (int)$sessionGroupId !== (int)$expense['group_id']) {
    http_response_code(403); exit;
}

// Verify the session member is active
$isMember = false;
try {
    $stmtM = db()->prepare(
        'SELECT id FROM members WHERE id = ? AND group_id = ? AND active = 1 LIMIT 1'
    );
    $stmtM->execute([$sessionMemberId, $expense['group_id']]);
    $isMember = (bool)$stmtM->fetch();
} catch (Throwable) {}

if (!$isMember) {
    http_response_code(403); exit;
}

// Build absolute path and sanity-check it stays within uploads directory
$uploadsDir = realpath(BASE_PATH . '/uploads');
$filePath   = realpath(BASE_PATH . '/' . $expense['receipt_path']);

if ($filePath === false || $uploadsDir === false || !str_starts_with($filePath, $uploadsDir . DIRECTORY_SEPARATOR)) {
    http_response_code(404); exit;
}

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404); exit;
}

$mime = mime_content_type($filePath) ?: 'application/octet-stream';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
    http_response_code(415); exit;
}

// Extra validation for PDFs: check magic bytes
if ($mime === 'application/pdf') {
    $fh = @fopen($filePath, 'rb');
    $magic = $fh ? fread($fh, 5) : '';
    if ($fh) fclose($fh);
    if ($magic !== '%PDF-') { http_response_code(415); exit; }
}

$size = filesize($filePath);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');

readfile($filePath);
exit;
