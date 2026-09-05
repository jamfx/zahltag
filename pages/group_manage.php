<?php
declare(strict_types=1);

$adminToken = $params['admin_token'] ?? '';
$group      = require_group_admin($adminToken);

// Load stats
$stats = ['members' => 0, 'expenses' => 0, 'total' => 0.0];
try {
    $stmtStats = db()->prepare(
        'SELECT
            (SELECT COUNT(*) FROM members WHERE group_id = ? AND active = 1) AS member_count,
            (SELECT COUNT(*) FROM expenses WHERE group_id = ?)             AS expense_count,
            (SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE group_id = ?) AS total_amount'
    );
    $stmtStats->execute([$group['id'], $group['id'], $group['id']]);
    $row = $stmtStats->fetch();
    if ($row) {
        $stats['members']  = (int)$row['member_count'];
        $stats['expenses'] = (int)$row['expense_count'];
        $stats['total']    = (float)$row['total_amount'];
    }
} catch (Throwable) {}

// Load recent expenses
$expenses = [];
try {
    $stmtExp = db()->prepare(
        'SELECT e.*, m.name AS paid_by_name
         FROM expenses e
         JOIN members m ON m.id = e.paid_by
         WHERE e.group_id = ?
         ORDER BY e.expense_date DESC, e.created_at DESC
         LIMIT 20'
    );
    $stmtExp->execute([$group['id']]);
    $expenses = $stmtExp->fetchAll();
} catch (Throwable) {}

// Load recent activity
$recentActivity = [];
try {
    $stmtAct = db()->prepare(
        'SELECT al.*, m.name AS member_name
         FROM activity_log al
         LEFT JOIN members m ON m.id = al.member_id
         WHERE al.group_id = ?
         ORDER BY al.created_at DESC
         LIMIT 15'
    );
    $stmtAct->execute([$group['id']]);
    $recentActivity = $stmtAct->fetchAll();
} catch (Throwable) {}

$shareUrl   = base_url('group/' . $group['share_token']);
$isArchived = $group['archived_at'] !== null;

$pageTitle = e($group['name']) . ' – ' . e(__('group.manage.title'));
$navLinks  = [
    ['url' => base_url('manage/' . $adminToken),                'label' => __('nav.overview'),         'icon' => 'fa-solid fa-gauge',     'active' => true],
    ['url' => base_url('manage/' . $adminToken . '/settings'),  'label' => __('group.settings.title'), 'icon' => 'fa-solid fa-sliders',   'active' => false],
    ['url' => base_url('manage/' . $adminToken . '/members'),   'label' => __('nav.members'),           'icon' => 'fa-solid fa-users',     'active' => false],
    ['url' => base_url('manage/' . $adminToken . '/export/pdf'),'label' => __('export.pdf'),            'icon' => 'fa-solid fa-file-pdf',  'active' => false],
    ['url' => base_url('manage/' . $adminToken . '/export/csv'),'label' => __('export.csv'),            'icon' => 'fa-solid fa-file-csv',  'active' => false],
];
if (!empty($_SESSION['admin_id'])) {
    array_unshift($navLinks, [
        'url'    => base_url('admin/dashboard'),
        'label'  => __('admin.dashboard.back_link'),
        'icon'   => 'fa-solid fa-arrow-left',
        'active' => false,
    ]);
}

ob_start();

if ($isArchived): ?>
<div class="flash flash--warning" role="alert">
    <span><?= e(__('group.archived_notice')) ?></span>
</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem">
    <div>
        <h1><?= e($group['name']) ?></h1>
        <p class="text-muted" style="font-size:0.875rem"><?= e(__('group.manage.title')) ?></p>
    </div>
    <a href="<?= e($shareUrl) ?>" class="btn btn--ghost btn--sm" target="_blank" rel="noopener">
        <?= e(__('group.manage.view_group')) ?> ↗
    </a>
</div>

<!-- Admin link (save this!) -->
<?php $manageUrl = base_url('manage/' . $adminToken); ?>
<div class="card" style="margin-bottom:1.5rem;border:2px solid var(--color-warning,#f59e0b)">
    <h2 style="font-size:1rem;margin-bottom:0.5rem">🔑 <?= e(__('group.manage.admin_link')) ?></h2>
    <p class="text-muted" style="font-size:0.875rem;margin-bottom:0.75rem"><?= e(__('group.manage.admin_link_hint')) ?></p>
    <div class="copy-field">
        <input type="text" id="admin-url" value="<?= e($manageUrl) ?>" readonly>
        <button type="button" class="btn btn--secondary btn--sm" data-copy-target="#admin-url">
            <?= e(__('common.copy')) ?>
        </button>
    </div>
</div>

<!-- Share link (prominent) -->
<div class="card" style="margin-bottom:1.5rem">
    <h2 style="font-size:1rem;margin-bottom:0.5rem"><?= e(__('group.settings.share_link')) ?></h2>
    <p class="text-muted" style="font-size:0.875rem;margin-bottom:0.75rem"><?= e(__('group.manage.share_prompt')) ?></p>
    <div class="copy-field">
        <input type="text" id="share-url" value="<?= e($shareUrl) ?>" readonly>
        <button type="button" class="btn btn--secondary btn--sm" data-copy-target="#share-url">
            <?= e(__('common.copy')) ?>
        </button>
    </div>

    <?php if ($stats['members'] === 0): ?>
    <div style="margin-top:1rem;padding:0.75rem 1rem;background:var(--color-primary-light);border-radius:var(--radius)">
        <strong><?= e(__('group.manage.join_as_member')) ?></strong><br>
        <small><?= e(__('group.manage.join_as_member_hint')) ?></small><br>
        <a href="<?= e($shareUrl) ?>" class="btn btn--primary btn--sm" style="margin-top:0.5rem">
            <?= e(__('group.manage.join_as_member')) ?> →
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="stat-grid" style="margin-bottom:1.5rem">
    <div class="stat-card">
        <p class="stat-card__value"><i class="fa-solid fa-users" aria-hidden="true" style="font-size:.8em;opacity:.6"></i> <?= $stats['members'] ?></p>
        <p class="stat-card__label"><?= e(__('group.manage.stat_members')) ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-card__value"><i class="fa-solid fa-receipt" aria-hidden="true" style="font-size:.8em;opacity:.6"></i> <?= $stats['expenses'] ?></p>
        <p class="stat-card__label"><?= e(__('group.manage.stat_expenses')) ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-card__value"><i class="fa-solid fa-coins" aria-hidden="true" style="font-size:.8em;opacity:.6"></i> <?= e(format_currency($stats['total'], $group['currency'])) ?></p>
        <p class="stat-card__label"><?= e(__('group.manage.stat_total')) ?></p>
    </div>
</div>

<!-- Expense list -->
<div class="card" style="margin-bottom:1.5rem">
    <h2><?= e(__('nav.expenses')) ?></h2>
    <?php if (empty($expenses)): ?>
        <p class="text-muted"><?= e(__('group.manage.no_expenses')) ?></p>
    <?php else: ?>
        <?php
        $adminMemberPalette = [
            'rgba(59,130,246,0.07)',
            'rgba(16,185,129,0.07)',
            'rgba(245,158,11,0.07)',
            'rgba(239,68,68,0.07)',
            'rgba(168,85,247,0.07)',
            'rgba(14,165,233,0.07)',
            'rgba(249,115,22,0.07)',
            'rgba(236,72,153,0.07)',
        ];
        $adminMemberColors = [];
        $adminColorIdx = 0;
        foreach ($expenses as $exp) {
            $mid = (int)$exp['paid_by'];
            if (!isset($adminMemberColors[$mid])) {
                $adminMemberColors[$mid] = $adminMemberPalette[$adminColorIdx % count($adminMemberPalette)];
                $adminColorIdx++;
            }
        }
        ?>
        <div style="overflow-x:auto">
        <table class="table">
            <thead>
                <tr>
                    <th scope="col"><?= e(__('expense.list.date')) ?></th>
                    <th scope="col"><?= e(__('expense.list.description')) ?></th>
                    <th scope="col"><?= e(__('expense.list.paid_by')) ?></th>
                    <th scope="col" class="text-right"><?= e(__('expense.list.amount')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($expenses as $exp): ?>
                <tr style="background:<?= $adminMemberColors[(int)$exp['paid_by']] ?? 'transparent' ?>">
                    <td><?= e(format_date($exp['expense_date'])) ?></td>
                    <td><?= e($exp['description']) ?></td>
                    <td><?= e($exp['paid_by_name']) ?></td>
                    <td class="text-right"><?= e(format_currency((float)$exp['amount'], $exp['currency'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<!-- Activity log -->
<?php if (!empty($recentActivity)): ?>
<div class="card">
    <h2><?= e(__('activity.recent')) ?></h2>
    <ul class="activity-list">
        <?php foreach ($recentActivity as $entry): ?>
        <li class="activity-list__item">
            <span class="activity-list__time"><?= e(format_date($entry['created_at'])) ?></span>
            <span class="activity-list__text">
            <?php
                $details = $entry['details'] ? (json_decode($entry['details'], true) ?? []) : [];
                echo e(__('activity.' . $entry['action'], $details));
            ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php else: ?>
<div class="card">
    <h2><?= e(__('activity.recent')) ?></h2>
    <p class="text-muted"><?= e(__('activity.none')) ?></p>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
