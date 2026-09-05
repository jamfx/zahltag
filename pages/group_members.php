<?php
declare(strict_types=1);

$adminToken = $params['admin_token'] ?? '';
$group      = require_group_admin($adminToken);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action   = $_POST['action'] ?? '';
    $memberId = (int)($_POST['member_id'] ?? 0);

    // Verify the member belongs to this group
    $stmtMember = db()->prepare('SELECT * FROM members WHERE id = ? AND group_id = ? LIMIT 1');
    $stmtMember->execute([$memberId, $group['id']]);
    $targetMember = $stmtMember->fetch();

    if (!$targetMember) {
        $errors[] = __('member.error.member_not_found');
    } elseif ($action === 'deactivate') {
        $stmtDeact = db()->prepare('UPDATE members SET active = 0 WHERE id = ? AND group_id = ?');
        $stmtDeact->execute([$memberId, $group['id']]);
        log_activity($group['id'], null, 'member_deactivated', ['name' => $targetMember['name']]);
        flash('success', __('member.deactivate') . ': ' . $targetMember['name']);
        redirect('/manage/' . $adminToken . '/members');
    } elseif ($action === 'reactivate') {
        $stmtReact = db()->prepare('UPDATE members SET active = 1 WHERE id = ? AND group_id = ?');
        $stmtReact->execute([$memberId, $group['id']]);
        flash('success', __('member.list_title'));
        redirect('/manage/' . $adminToken . '/members');
    } elseif ($action === 'reset_pin') {
        $stmtReset = db()->prepare('UPDATE members SET pin_hash = NULL WHERE id = ? AND group_id = ?');
        $stmtReset->execute([$memberId, $group['id']]);
        log_activity($group['id'], null, 'member_pin_reset', ['name' => $targetMember['name']]);
        flash('success', __('member.reset_pin') . ': ' . $targetMember['name']);
        redirect('/manage/' . $adminToken . '/members');
    } elseif ($action === 'set_weight') {
        $weight = max(0.01, parse_amount(trim($_POST['weight'] ?? '1')));
        $stmtW = db()->prepare('UPDATE members SET default_weight = ? WHERE id = ? AND group_id = ?');
        $stmtW->execute([$weight, $memberId, $group['id']]);
        flash('success', __('member.weight_saved'));
        redirect('/manage/' . $adminToken . '/members');
    }
}

// Load all members (active and inactive) including default_weight
$members = [];
try {
    $stmtAll = db()->prepare(
        'SELECT m.*,
            (SELECT COUNT(*) FROM expenses WHERE paid_by = m.id) AS expense_count
         FROM members m
         WHERE m.group_id = ?
         ORDER BY m.active DESC, m.name ASC'
    );
    $stmtAll->execute([$group['id']]);
    $members = $stmtAll->fetchAll();
} catch (Throwable) {}

$pageTitle = e($group['name']) . ' – ' . e(__('member.list_title'));
$navLinks  = [
    ['url' => base_url('manage/' . $adminToken),                'label' => __('nav.overview'),         'icon' => 'fa-solid fa-gauge',    'active' => false],
    ['url' => base_url('manage/' . $adminToken . '/settings'),  'label' => __('group.settings.title'), 'icon' => 'fa-solid fa-sliders',  'active' => false],
    ['url' => base_url('manage/' . $adminToken . '/members'),   'label' => __('nav.members'),           'icon' => 'fa-solid fa-users',    'active' => true],
    ['url' => base_url('manage/' . $adminToken . '/export/pdf'),'label' => __('export.pdf'),            'icon' => 'fa-solid fa-file-pdf', 'active' => false],
    ['url' => base_url('manage/' . $adminToken . '/export/csv'),'label' => __('export.csv'),            'icon' => 'fa-solid fa-file-csv', 'active' => false],
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
?>
<h1><?= e(__('member.list_title')) ?></h1>
<p class="text-muted" style="margin-bottom:1.5rem"><?= e($group['name']) ?></p>

<?php if (!empty($errors)): ?>
<div class="flash flash--error" role="alert">
    <ul style="margin:0;padding-left:1.25rem">
        <?php foreach ($errors as $err): ?>
            <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (empty($members)): ?>
<div class="card">
    <p class="text-muted"><?= e(__('activity.none')) ?></p>
</div>
<?php else: ?>
<div class="card" style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr>
                <th scope="col"><?= e(__('member.your_name')) ?></th>
                <th scope="col"><?= e(__('nav.expenses')) ?></th>
                <th scope="col"><?= e(__('member.pin_label')) ?></th>
                <th scope="col" title="<?= e(__('member.default_weight_hint')) ?>"><?= e(__('member.default_weight_col')) ?></th>
                <th scope="col" style="white-space:nowrap;width:1%"><?= e(__('expense.list.actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($members as $m): ?>
        <tr class="<?= $m['active'] ? '' : 'row--inactive' ?>">
            <td>
                <?= e($m['name']) ?>
                <?php if (!$m['active']): ?>
                    <span class="badge badge--neutral"><?= e(__('member.inactive_badge')) ?></span>
                <?php endif; ?>
            </td>
            <td><?= (int)$m['expense_count'] ?></td>
            <td>
                <?php if ($group['pin_required']): ?>
                    <?= $m['pin_hash'] !== null
                        ? '<span class="badge badge--success">✓</span>'
                        : '<span class="badge badge--warning">–</span>' ?>
                <?php else: ?>
                    <span class="text-muted">–</span>
                <?php endif; ?>
            </td>
            <td style="white-space:nowrap">
                <?php if ($m['active']): ?>
                <form method="post" style="display:flex;align-items:center;gap:.25rem">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_weight">
                    <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                    <input type="number"
                           name="weight"
                           value="<?= e(rtrim(rtrim(number_format((float)($m['default_weight'] ?? 1.0), 2, '.', ''), '0'), '.') ?: '1') ?>"
                           min="0.01" step="0.01" style="width:4.5rem"
                           title="<?= e(__('member.default_weight_hint')) ?>">
                    <button type="submit" class="btn btn--ghost btn--sm" title="<?= e(__('common.save')) ?>">✓</button>
                </form>
                <?php else: ?>
                <span class="text-muted">–</span>
                <?php endif; ?>
            </td>
            <td style="width:1%">
                <?php
                $mCsrf         = e(csrf_token());
                $mDeleteLabel  = e(__('member.deactivate', ['name' => $m['name']]));
                $mDeleteConfirm = e(__('member.deactivate_confirm', ['name' => $m['name']]));
                $mResetLabel   = e(__('member.reset_pin'));
                $mResetConfirm = e(__('member.reset_pin_confirm', ['name' => $m['name']]));
                $hasReset = $group['pin_required'] && $m['pin_hash'] !== null;
                ?>
                <?php if ($m['active']): ?>
                <!-- Desktop: icon-only -->
                <div class="dash-actions">
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= $mCsrf ?>">
                        <input type="hidden" name="action" value="deactivate">
                        <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" class="btn btn--ghost btn--sm btn--danger"
                                data-confirm="<?= $mDeleteConfirm ?>" title="<?= $mDeleteLabel ?>">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                        </button>
                    </form>
                    <?php if ($hasReset): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= $mCsrf ?>">
                        <input type="hidden" name="action" value="reset_pin">
                        <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" class="btn btn--ghost btn--sm"
                                data-confirm="<?= $mResetConfirm ?>" title="<?= $mResetLabel ?>">
                            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <!-- Mobile: dropdown -->
                <details class="dash-actions-menu">
                    <summary class="btn btn--ghost btn--sm"
                             aria-label="<?= e(__('common.actions')) ?>">
                        <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                    </summary>
                    <div class="dash-actions-menu__panel">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= $mCsrf ?>">
                            <input type="hidden" name="action" value="deactivate">
                            <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="dash-actions-menu__form-btn dash-actions-menu__form-btn--danger"
                                    data-confirm="<?= $mDeleteConfirm ?>">
                                <i class="fa-solid fa-trash" aria-hidden="true"></i> <?= $mDeleteLabel ?>
                            </button>
                        </form>
                        <?php if ($hasReset): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= $mCsrf ?>">
                            <input type="hidden" name="action" value="reset_pin">
                            <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="dash-actions-menu__form-btn"
                                    data-confirm="<?= $mResetConfirm ?>">
                                <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> <?= $mResetLabel ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </details>
                <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= $mCsrf ?>">
                    <input type="hidden" name="action" value="reactivate">
                    <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                    <button type="submit" class="btn btn--ghost btn--sm"><?= e(__('common.confirm')) ?></button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
