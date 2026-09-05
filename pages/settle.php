<?php
declare(strict_types=1);

$shareToken    = $params['share_token'] ?? '';
$currentMember = require_member($shareToken);
$group         = get_group_by_share_token($shareToken);

if (!$group) {
    http_response_code(404); exit;
}

$groupId   = (int)$group['id'];
$isEur     = strtoupper($group['currency']) === 'EUR';
$currentId = (int)$currentMember['id'];

// ─── Data loading ─────────────────────────────────────────────────────────────
$balances    = calculate_balances($groupId);
$settlements = calculate_settlements($balances);

// Load payment details for all members (for GiroCode and payment links)
$memberPayments = [];
try {
    $stmt = db()->prepare(
        'SELECT id, name, payment_paypal, payment_wero, payment_iban, payment_iban_name
         FROM members WHERE group_id = ?'
    );
    $stmt->execute([$groupId]);
    foreach ($stmt->fetchAll() as $m) {
        $memberPayments[(int)$m['id']] = $m;
    }
} catch (Throwable) {}

// Load all recorded payments (unconfirmed and confirmed)
$recordedPayments = [];
try {
    $stmt = db()->prepare(
        'SELECT p.*, mf.name AS from_name, mt.name AS to_name
         FROM payments p
         JOIN members mf ON mf.id = p.from_member_id
         JOIN members mt ON mt.id = p.to_member_id
         WHERE p.group_id = ?
         ORDER BY p.created_at DESC'
    );
    $stmt->execute([$groupId]);
    $recordedPayments = $stmt->fetchAll();
} catch (Throwable) {}

// Index unconfirmed payments by (from, to) so we can mark suggested payments as "pending"
$pendingKey = [];
foreach ($recordedPayments as $rp) {
    if (!(int)$rp['confirmed_by_recipient']) {
        $k = (int)$rp['from_member_id'] . '_' . (int)$rp['to_member_id'];
        $pendingKey[$k] = (int)$rp['id'];
    }
}

// ─── Render ───────────────────────────────────────────────────────────────────
$pageTitle = __('settlement.title');
$navLinks  = [
    ['url' => base_url('group/' . $shareToken),                  'label' => __('nav.expenses'), 'icon' => 'fa-solid fa-receipt',        'active' => false],
    ['url' => base_url('group/' . $shareToken . '/settle'),       'label' => __('nav.settle'),   'icon' => 'fa-solid fa-scale-balanced', 'active' => true],
    ['url' => base_url('group/' . $shareToken . '/payment-data'), 'label' => __('group.view.my_payment_data'), 'icon' => 'fa-solid fa-wallet', 'active' => false],
];

// Sort balances: creditors first, then zero, then debtors
usort($balances, fn($a, $b) => $b['balance'] <=> $a['balance']);

// Max absolute balance for bar chart scaling
$maxAbsBalance = max(0.01, ...array_map(fn($b) => abs($b['balance']), $balances));

ob_start();
?>
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem">
    <h1><?= e(__('settlement.title')) ?></h1>
    <a href="<?= base_url('group/' . $shareToken . '/payment-data') ?>" class="btn btn--ghost btn--sm">
        <?= e(__('group.view.my_payment_data')) ?>
    </a>
</div>

<!-- ── Balance chart ─────────────────────────────────────────────────────── -->
<div class="card">
    <h2><?= e(__('settlement.balances_title')) ?></h2>

    <div class="balance-chart" aria-hidden="true">
    <?php foreach ($balances as $b): ?>
    <?php
        $pct   = min(100, round(abs($b['balance']) / $maxAbsBalance * 100, 1));
        $isPos = $b['balance'] > 0.005;
        $isNeg = $b['balance'] < -0.005;
        $isMe  = $b['id'] === $currentId;
    ?>
    <div class="balance-chart__row<?= $isMe ? ' balance-chart__row--me' : '' ?>">
        <span class="balance-chart__name"><?= e($b['name']) ?></span>
        <div class="balance-chart__track">
            <div class="balance-chart__neg-side">
                <?php if ($isNeg): ?>
                <div class="balance-chart__bar balance-chart__bar--neg" style="width:<?= $pct ?>%"></div>
                <?php endif; ?>
            </div>
            <div class="balance-chart__zero-line"></div>
            <div class="balance-chart__pos-side">
                <?php if ($isPos): ?>
                <div class="balance-chart__bar balance-chart__bar--pos" style="width:<?= $pct ?>%"></div>
                <?php endif; ?>
            </div>
        </div>
        <span class="balance-chart__value <?= $isPos ? 'balance-positive' : ($isNeg ? 'balance-negative' : '') ?>">
            <?php if ($isPos): ?>+ <?= e(format_currency($b['balance'], $group['currency'])) ?>
            <?php elseif ($isNeg): ?>− <?= e(format_currency(abs($b['balance']), $group['currency'])) ?>
            <?php else: ?><?= e(format_currency(0, $group['currency'])) ?>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>
    </div>

    <div style="overflow-x:auto;margin-top:1.5rem">
    <table class="table">
        <thead>
            <tr>
                <th scope="col"><?= e(__('expense.list.paid_by')) ?></th>
                <th scope="col" class="text-right"><?= e(__('settlement.paid_total')) ?></th>
                <th scope="col" class="text-right"><?= e(__('settlement.owes_total')) ?></th>
                <th scope="col" class="text-right"><?= e(__('settlement.balance')) ?></th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($balances as $b): ?>
        <?php
            $isMe    = $b['id'] === $currentId;
            $balance = $b['balance'];
            $bClass  = $balance > 0.005 ? 'balance-positive' : ($balance < -0.005 ? 'balance-negative' : '');
        ?>
        <tr<?= $isMe ? ' class="row--highlight"' : '' ?>>
            <td>
                <?= e($b['name']) ?>
                <?php if ($isMe): ?><span class="badge badge--info" style="margin-left:.5rem"><i class="fa-solid fa-user" aria-hidden="true"></i> <?= e(__('common.you')) ?></span><?php endif; ?>
            </td>
            <td class="text-right"><?= e(format_currency($b['paid'], $group['currency'])) ?></td>
            <td class="text-right"><?= e(format_currency($b['owes'], $group['currency'])) ?></td>
            <td class="text-right">
                <span class="<?= e($bClass) ?>">
                    <?php if ($balance > 0.005): ?>
                        + <?= e(format_currency($balance, $group['currency'])) ?>
                    <?php elseif ($balance < -0.005): ?>
                        − <?= e(format_currency(abs($balance), $group['currency'])) ?>
                    <?php else: ?>
                        <?= e(format_currency(0, $group['currency'])) ?>
                    <?php endif; ?>
                </span>
            </td>
            <td style="white-space:nowrap">
                <?php if ($balance > 0.005): ?>
                    <span class="badge badge--success"><?= e(__('settlement.positive_balance')) ?></span>
                <?php elseif ($balance < -0.005): ?>
                    <span class="badge badge--danger"><?= e(__('settlement.negative_balance')) ?></span>
                <?php else: ?>
                    <span class="badge"><?= e(__('settlement.zero_balance')) ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div><!-- /overflow-x:auto -->
</div>

<!-- ── Suggested payments ──────────────────────────────────────────────────── -->
<div class="card" style="margin-top:2rem">
    <h2><?= e(__('settlement.payments_title')) ?></h2>

    <?php if (empty($settlements)): ?>
    <p class="text-muted"><?= e(__('settlement.no_payments_needed')) ?></p>

    <?php else: ?>
    <div class="payment-cards">
    <?php foreach ($settlements as $s):
        $pendingLookup = $s['from_id'] . '_' . $s['to_id'];
        $isPending     = isset($pendingKey[$pendingLookup]);
        $isMyPayment   = $s['from_id'] === $currentId;
        $isMyReceipt   = $s['to_id']   === $currentId;
        $recipient     = $memberPayments[$s['to_id']] ?? null;

        // GiroCode: only if group is EUR and recipient has IBAN
        $girocodeImg = '';
        if ($isEur && $recipient && !empty($recipient['payment_iban'])) {
            $ibanName  = $recipient['payment_iban_name'] ?: $recipient['name'];
            $reference = __('girocode.reference', ['group' => $group['name']]);
            $girocodeImg = generate_girocode_base64(
                $recipient['payment_iban'],
                $ibanName,
                $s['amount'],
                $reference
            );
        }
    ?>
    <div class="payment-card<?= $isPending ? ' payment-card--pending' : '' ?>">
        <div class="payment-card__header">
            <span class="payment-card__arrow">
                <strong><?= e($s['from_name']) ?></strong>
                &rarr;
                <strong><?= e($s['to_name']) ?></strong>
            </span>
            <span class="payment-card__amount">
                <?= e(format_currency($s['amount'], $group['currency'])) ?>
            </span>
        </div>

        <?php if ($recipient): ?>
        <div class="payment-card__options">
            <?php if ($recipient['payment_iban']): ?>
            <div class="payment-option">
                <span class="payment-option__label"><i class="fa-solid fa-building-columns" aria-hidden="true"></i> <?= e(__('settlement.payment_via_iban')) ?></span>
                <span class="payment-option__value">
                    <?= e(format_iban($recipient['payment_iban'])) ?>
                    <?php if ($recipient['payment_iban_name']): ?>
                    (<?= e($recipient['payment_iban_name']) ?>)
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>
            <?php if ($recipient['payment_paypal']): ?>
            <?php
                $pp     = $recipient['payment_paypal'];
                $ppLink = null;
                if (str_starts_with($pp, 'https://') || str_starts_with($pp, 'http://')) {
                    $ppLink = $pp;
                } elseif (str_starts_with($pp, 'paypal.me/')) {
                    $ppLink = 'https://' . $pp;
                } elseif (!str_contains($pp, '@')) {
                    $ppLink = 'https://paypal.me/' . $pp;
                }
            ?>
            <div class="payment-option">
                <span class="payment-option__label"><i class="fa-brands fa-paypal" aria-hidden="true"></i> <?= e(__('settlement.payment_via_paypal')) ?></span>
                <span class="payment-option__value">
                    <?php if ($ppLink): ?>
                    <a href="<?= e($ppLink) ?>" target="_blank" rel="noopener"><?= e($pp) ?></a>
                    <?php else: ?>
                    <?= e($pp) ?>
                    <?php endif; ?>
                </span>
                <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($pp) ?>"
                        style="margin-left:.5rem;flex-shrink:0"><?= e(__('common.copy')) ?></button>
            </div>
            <?php endif; ?>
            <?php if ($recipient['payment_wero']): ?>
            <div class="payment-option">
                <span class="payment-option__label"><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i> <?= e(__('settlement.payment_via_wero')) ?></span>
                <span class="payment-option__value"><?= e($recipient['payment_wero']) ?></span>
                <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($recipient['payment_wero']) ?>"
                        style="margin-left:.5rem;flex-shrink:0"><?= e(__('common.copy')) ?></button>
            </div>
            <?php endif; ?>
            <?php if (!$recipient['payment_iban'] && !$recipient['payment_paypal'] && !$recipient['payment_wero']): ?>
            <p class="text-muted" style="font-size:.875rem"><?= e(__('payment.no_data')) ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($girocodeImg): ?>
        <div class="payment-card__girocode">
            <p class="payment-option__label"><?= e(__('settlement.girocode_title')) ?></p>
            <img src="<?= e($girocodeImg) ?>" alt="GiroCode QR" style="width:140px;height:140px;display:block">
            <small class="text-muted"><?= e(__('settlement.girocode_hint')) ?></small>
        </div>
        <?php endif; ?>

        <div class="payment-card__actions">
            <?php if ($isPending): ?>
            <span class="badge badge--warning"><?= e(__('settlement.status_unconfirmed')) ?></span>

            <?php elseif ($isMyPayment): ?>
            <form method="post" action="<?= base_url('group/' . $shareToken . '/mark-paid') ?>">
                <input type="hidden" name="csrf_token"     value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="to_member_id"  value="<?= $s['to_id'] ?>">
                <input type="hidden" name="amount"         value="<?= number_format($s['amount'], 2, '.', '') ?>">
                <button type="submit" class="btn btn--primary btn--sm"
                        data-confirm="<?= e(__('settlement.mark_paid_confirm', ['amount' => format_currency($s['amount'], $group['currency']), 'to' => $s['to_name']])) ?>">
                    <?= e(__('settlement.mark_paid')) ?>
                </button>
            </form>

            <?php elseif (!$isMyPayment && !$isMyReceipt): ?>
            <span class="badge"><?= e(__('settlement.status_pending')) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Recorded payments ───────────────────────────────────────────────────── -->
<?php if (!empty($recordedPayments)): ?>
<div class="card" style="margin-top:2rem">
    <h2><?= e(__('activity.recent')) ?></h2>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th scope="col"><?= e(__('expense.list.date')) ?></th>
                <th scope="col"><?= e(__('expense.list.paid_by')) ?></th>
                <th scope="col"><?= e(__('expense.list.amount')) ?></th>
                <th scope="col"><?= e(__('common.filter')) ?></th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recordedPayments as $rp): ?>
        <?php
            $confirmed    = (bool)(int)$rp['confirmed_by_recipient'];
            $isRecipient  = (int)$rp['to_member_id'] === $currentId;
        ?>
        <tr>
            <td style="white-space:nowrap"><?= e(format_date($rp['created_at'])) ?></td>
            <td><?= e($rp['from_name']) ?> → <?= e($rp['to_name']) ?></td>
            <td class="text-right"><?= e(format_currency((float)$rp['amount'], $group['currency'])) ?></td>
            <td>
                <?php if ($confirmed): ?>
                <span class="badge badge--success"><?= e(__('settlement.status_confirmed')) ?></span>
                <?php else: ?>
                <span class="badge badge--warning"><?= e(__('settlement.status_unconfirmed')) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!$confirmed && $isRecipient): ?>
                <form method="post" action="<?= base_url('group/' . $shareToken . '/confirm-payment') ?>">
                    <input type="hidden" name="csrf_token"   value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="payment_id"  value="<?= (int)$rp['id'] ?>">
                    <button type="submit" class="btn btn--secondary btn--sm"
                            data-confirm="<?= e(__('settlement.confirm_receipt_text', ['from' => $rp['from_name'], 'amount' => format_currency((float)$rp['amount'], $group['currency'])])) ?>">
                        <?= e(__('settlement.confirm_receipt')) ?>
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
