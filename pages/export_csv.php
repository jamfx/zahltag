<?php
declare(strict_types=1);

$adminToken = $params['admin_token'] ?? '';
$group      = require_group_admin($adminToken);
$groupId    = (int)$group['id'];

// Load all expenses with payer name
$expenses = [];
try {
    $stmt = db()->prepare(
        'SELECT e.*, m.name AS paid_by_name
         FROM expenses e
         JOIN members m ON m.id = e.paid_by
         WHERE e.group_id = ?
         ORDER BY e.expense_date ASC, e.created_at ASC'
    );
    $stmt->execute([$groupId]);
    $expenses = $stmt->fetchAll();
} catch (Throwable) {}

if (empty($expenses)) {
    flash('info', __('group.manage.no_expenses'));
    redirect('/manage/' . $adminToken);
}

// Load all splits keyed by expense_id → [member_id => share_amount]
$expenseIds = array_column($expenses, 'id');
$allSplits  = [];
if (!empty($expenseIds)) {
    try {
        $in   = implode(',', array_fill(0, count($expenseIds), '?'));
        $stmt = db()->prepare(
            "SELECT es.expense_id, es.member_id, es.share_amount, m.name AS member_name
             FROM expense_splits es
             JOIN members m ON m.id = es.member_id
             WHERE es.expense_id IN ($in)"
        );
        $stmt->execute($expenseIds);
        foreach ($stmt->fetchAll() as $row) {
            $allSplits[(int)$row['expense_id']][] = $row;
        }
    } catch (Throwable) {}
}

// Load custom category names
$customCatNames = [];
try {
    $stmt = db()->prepare('SELECT id, name FROM custom_categories WHERE group_id = ?');
    $stmt->execute([$groupId]);
    foreach ($stmt->fetchAll() as $row) {
        $customCatNames[(int)$row['id']] = $row['name'];
    }
} catch (Throwable) {}

// Build filename
$safeGroupName = preg_replace('/[^\p{L}\p{N}_\-]/u', '_', $group['name']);
$dateStr       = date('Y-m-d');
$filename      = str_replace(
    ['{group}', '{date}'],
    [$safeGroupName, $dateStr],
    __('export.csv_filename')
);

// Output headers
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility
fwrite($output, "\xEF\xBB\xBF");

// Column headers
fputcsv($output, [
    __('export.csv_col_nr'),
    __('export.csv_col_date'),
    __('export.csv_col_description'),
    __('export.csv_col_paid_by'),
    __('export.csv_col_amount'),
    __('export.csv_col_currency'),
    __('export.csv_col_category'),
    __('export.csv_col_splits'),
], ';');

// Data rows
foreach ($expenses as $i => $exp) {
    // Category label
    $category = '';
    if ($exp['category_preset']) {
        $category = __('expense.categories.' . $exp['category_preset']);
    } elseif ($exp['category_custom_id'] && isset($customCatNames[(int)$exp['category_custom_id']])) {
        $category = $customCatNames[(int)$exp['category_custom_id']];
    }

    // Splits: "Name1:amount1;Name2:amount2"
    $splits     = $allSplits[(int)$exp['id']] ?? [];
    $splitParts = [];
    foreach ($splits as $s) {
        $splitParts[] = $s['member_name'] . ':' . number_format((float)$s['share_amount'], 2, '.', '');
    }
    $splitsStr = implode(';', $splitParts);

    fputcsv($output, [
        $i + 1,
        $exp['expense_date'],
        $exp['description'],
        $exp['paid_by_name'],
        number_format((float)$exp['amount'], 2, '.', ''),
        $exp['currency'],
        $category,
        $splitsStr,
    ], ';');
}

fclose($output);
exit;
