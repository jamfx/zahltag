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

$toMemberId = (int)($_POST['to_member_id'] ?? 0);
$amountRaw  = trim($_POST['amount'] ?? '');
$amount     = round((float)$amountRaw, 2);
$groupId    = (int)$group['id'];
$fromId     = (int)$currentMember['id'];

if ($toMemberId === $fromId) {
    flash('error', __('settlement.mark_paid_confirm'));
    redirect('/group/' . $shareToken . '/settle');
}

if ($amount <= 0) {
    redirect('/group/' . $shareToken . '/settle');
}

// Verify to_member belongs to this group
$toMember = null;
try {
    $stmt = db()->prepare('SELECT id, name FROM members WHERE id = ? AND group_id = ? LIMIT 1');
    $stmt->execute([$toMemberId, $groupId]);
    $toMember = $stmt->fetch() ?: null;
} catch (Throwable) {}

if (!$toMember) {
    http_response_code(403); exit;
}

// Prevent duplicate pending payments for the same pair
$existing = null;
try {
    $stmtChk = db()->prepare(
        'SELECT id FROM payments
         WHERE group_id = ? AND from_member_id = ? AND to_member_id = ?
           AND confirmed_by_recipient = 0
         LIMIT 1'
    );
    $stmtChk->execute([$groupId, $fromId, $toMemberId]);
    $existing = $stmtChk->fetchColumn();
} catch (Throwable) {}

if ($existing) {
    flash('info', __('settlement.status_unconfirmed'));
    redirect('/group/' . $shareToken . '/settle');
}

try {
    $stmtIns = db()->prepare(
        'INSERT INTO payments (group_id, from_member_id, to_member_id, amount) VALUES (?, ?, ?, ?)'
    );
    $stmtIns->execute([$groupId, $fromId, $toMemberId, $amount]);

    log_activity($groupId, $fromId, 'payment_marked', [
        'from'   => $currentMember['name'],
        'to'     => $toMember['name'],
        'amount' => format_currency($amount, $group['currency']),
    ]);
} catch (Throwable $e) {
    error_log('mark_paid error: ' . $e->getMessage());
    flash('error', __('common.error'));
    redirect('/group/' . $shareToken . '/settle');
}

flash('success', __('settlement.mark_paid_success'));
redirect('/group/' . $shareToken . '/settle');
