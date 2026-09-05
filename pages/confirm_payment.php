<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit;
}

$shareToken    = $params['share_token'] ?? '';
$currentMember = require_member($shareToken);
$group         = get_group_by_share_token($shareToken);

if (!$group) {
    http_response_code(404); exit;
}

verify_csrf();

$paymentId = (int)($_POST['payment_id'] ?? 0);
$groupId   = (int)$group['id'];
$currentId = (int)$currentMember['id'];

// Load payment
$payment = null;
try {
    $stmt = db()->prepare(
        'SELECT p.*, mf.name AS from_name
         FROM payments p
         JOIN members mf ON mf.id = p.from_member_id
         WHERE p.id = ? AND p.group_id = ? LIMIT 1'
    );
    $stmt->execute([$paymentId, $groupId]);
    $payment = $stmt->fetch() ?: null;
} catch (Throwable) {}

if (!$payment) {
    http_response_code(404); exit;
}

// Only the recipient may confirm
if ((int)$payment['to_member_id'] !== $currentId) {
    http_response_code(403); exit;
}

// Already confirmed?
if ((int)$payment['confirmed_by_recipient']) {
    redirect('/group/' . $shareToken . '/settle');
}

try {
    $stmtUpd = db()->prepare(
        'UPDATE payments SET confirmed_by_recipient = 1, confirmed_at = NOW() WHERE id = ?'
    );
    $stmtUpd->execute([$paymentId]);

    log_activity($groupId, $currentId, 'payment_confirmed', [
        'from'   => $payment['from_name'],
        'to'     => $currentMember['name'],
        'amount' => format_currency((float)$payment['amount'], $group['currency']),
    ]);
} catch (Throwable $e) {
    error_log('confirm_payment error: ' . $e->getMessage());
    flash('error', __('common.error'));
    redirect('/group/' . $shareToken . '/settle');
}

flash('success', __('settlement.confirm_receipt_success'));
redirect('/group/' . $shareToken . '/settle');
