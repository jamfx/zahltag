<?php
declare(strict_types=1);

$shareToken = $params['share_token'] ?? '';
$expenseId  = (int)($params['expense_id'] ?? 0);
$member     = require_member($shareToken);
$group      = get_group_by_share_token($shareToken);

if (!$group || $group['archived_at'] !== null) {
    http_response_code(404); exit;
}

// Load expense
$expense = null;
try {
    $stmt = db()->prepare('SELECT * FROM expenses WHERE id = ? AND group_id = ? LIMIT 1');
    $stmt->execute([$expenseId, $group['id']]);
    $expense = $stmt->fetch() ?: null;
} catch (Throwable) {}

if (!$expense) {
    http_response_code(404); exit;
}

// Only the payer may delete
if ((int)$expense['paid_by'] !== (int)$member['id']) {
    http_response_code(403); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
}

verify_csrf();

// Delete receipt file
if ($expense['receipt_path']) {
    $filePath = BASE_PATH . '/' . ltrim($expense['receipt_path'], '/');
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
}

$description = $expense['description'];

// Splits deleted via ON DELETE CASCADE on expense_splits.expense_id FK
$stmtDel = db()->prepare('DELETE FROM expenses WHERE id = ? AND group_id = ?');
$stmtDel->execute([$expenseId, $group['id']]);

log_activity($group['id'], (int)$member['id'], 'expense_deleted', [
    'description' => $description,
    'name'        => $member['name'],
]);

flash('success', __('expense.delete.success'));
redirect('/group/' . $shareToken);
