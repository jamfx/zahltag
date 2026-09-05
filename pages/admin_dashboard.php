<?php
declare(strict_types=1);

require_admin();

// Run cleanup of old archived groups (lazy execution on every dashboard load)
cleanup_archived_groups();

// ─── Actions ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action  = $_POST['action']   ?? '';
    $groupId = (int)($_POST['group_id'] ?? 0);

    if ($action === 'archive' && $groupId) {
        try {
            db()->prepare('UPDATE groups SET archived_at = NOW() WHERE id = ? AND archived_at IS NULL')
                ->execute([$groupId]);
        } catch (Throwable) {}
        flash('success', __('admin.dashboard.archive'));
    } elseif ($action === 'delete' && $groupId) {
        // Delete receipt files first
        try {
            $stmtR = db()->prepare(
                'SELECT receipt_path FROM expenses WHERE group_id = ? AND receipt_path IS NOT NULL'
            );
            $stmtR->execute([$groupId]);
            foreach ($stmtR->fetchAll() as $row) {
                $fp = BASE_PATH . '/' . ltrim($row['receipt_path'], '/');
                if (file_exists($fp)) @unlink($fp);
            }
            db()->prepare('DELETE FROM groups WHERE id = ?')->execute([$groupId]);
        } catch (Throwable) {}
        flash('success', __('admin.dashboard.delete'));
    }
    redirect('/admin/dashboard');
}

// ─── Load groups ──────────────────────────────────────────────────────────────
$groups = [];
try {
    $groups = db()->query(
        'SELECT g.id, g.name, g.currency, g.admin_token, g.share_token, g.archived_at, g.created_at,
                (SELECT COUNT(*) FROM members m WHERE m.group_id = g.id) AS member_count,
                (SELECT COUNT(*) FROM expenses e WHERE e.group_id = g.id) AS expense_count,
                (SELECT COALESCE(SUM(e2.amount),0) FROM expenses e2 WHERE e2.group_id = g.id) AS total_amount
         FROM groups g
         ORDER BY g.created_at DESC'
    )->fetchAll();
} catch (Throwable) {}

// ─── Render ───────────────────────────────────────────────────────────────────
$pageTitle = __('admin.dashboard.title');
$navLinks  = [
    ['url' => base_url('admin/dashboard'), 'label' => __('admin.dashboard.title'), 'icon' => 'fa-solid fa-gauge',       'active' => true],
    ['url' => base_url('admin/settings'),  'label' => __('admin.settings.title'),  'icon' => 'fa-solid fa-sliders',     'active' => false],
    ['url' => base_url('admin/profile'),   'label' => __('admin.nav.profile'),     'icon' => 'fa-solid fa-circle-user', 'active' => false],
    ['type' => 'logout', 'label' => __('admin.login.logout'), 'icon' => 'fa-solid fa-right-from-bracket'],
];

// ─── Compute statistics ───────────────────────────────────────────────────────
$statsTotal    = count($groups);
$statsActive   = 0;
$statsArchived = 0;
$statsMembers  = 0;
$statsExpenses = 0;
$statsThisMonth = 0;
$thisMonth = date('Y-m');
foreach ($groups as $g) {
    if ($g['archived_at']) {
        $statsArchived++;
    } else {
        $statsActive++;
    }
    $statsMembers  += (int)$g['member_count'];
    $statsExpenses += (int)$g['expense_count'];
    if (str_starts_with($g['created_at'], $thisMonth)) {
        $statsThisMonth++;
    }
}
$statsAvgExpenses = $statsTotal > 0 ? round($statsExpenses / $statsTotal, 1) : 0;

ob_start();
?>
<h1 style="margin-bottom:1.5rem"><?= e(__('admin.dashboard.title')) ?></h1>

<!-- Statistics -->
<div class="stat-grid" style="margin-bottom:1.5rem">
    <div class="stat-card">
        <p class="stat-card__value"><?= $statsTotal ?></p>
        <p class="stat-card__label"><?= e(__('admin.dashboard.stat_groups')) ?><br>
            <small class="text-muted"><?= $statsActive ?> <?= e(__('admin.dashboard.status_active')) ?> · <?= $statsArchived ?> <?= e(__('admin.dashboard.status_archived')) ?></small>
        </p>
    </div>
    <div class="stat-card">
        <p class="stat-card__value"><?= $statsMembers ?></p>
        <p class="stat-card__label"><?= e(__('admin.dashboard.stat_members')) ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-card__value"><?= $statsExpenses ?></p>
        <p class="stat-card__label"><?= e(__('admin.dashboard.stat_expenses')) ?><br>
            <small class="text-muted">Ø <?= $statsAvgExpenses ?> <?= e(__('admin.dashboard.stat_per_group')) ?></small>
        </p>
    </div>
    <div class="stat-card">
        <p class="stat-card__value"><?= $statsThisMonth ?></p>
        <p class="stat-card__label"><?= e(__('admin.dashboard.stat_new_this_month')) ?></p>
    </div>
</div>

<div class="card">
    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
        <input type="search" id="group-search" placeholder="<?= e(__('admin.dashboard.search_placeholder')) ?>"
               style="flex:1;max-width:300px">
    </div>

    <?php if (empty($groups)): ?>
    <p class="text-muted"><?= e(__('admin.dashboard.no_groups')) ?></p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table" id="groups-table">
        <thead>
            <tr>
                <th scope="col"><?= e(__('admin.dashboard.col_name')) ?></th>
                <th scope="col" class="text-right"><?= e(__('admin.dashboard.col_members')) ?></th>
                <th scope="col" class="text-right"><?= e(__('admin.dashboard.col_expenses')) ?></th>
                <th scope="col" class="text-right"><?= e(__('admin.dashboard.col_total')) ?></th>
                <th scope="col" style="white-space:nowrap"><?= e(__('admin.dashboard.col_created')) ?></th>
                <th scope="col"><?= e(__('admin.dashboard.col_status')) ?></th>
                <th scope="col" style="white-space:nowrap;width:1%"><?= e(__('admin.dashboard.col_actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
        <tr data-name="<?= e(strtolower($g['name'])) ?>">
            <td><strong><?= e($g['name']) ?></strong></td>
            <td class="text-right"><?= (int)$g['member_count'] ?></td>
            <td class="text-right"><?= (int)$g['expense_count'] ?></td>
            <td class="text-right" style="white-space:nowrap"><?= e(format_currency((float)$g['total_amount'], $g['currency'])) ?></td>
            <td style="white-space:nowrap"><?= e(format_date(substr($g['created_at'], 0, 10))) ?></td>
            <td>
                <?php if ($g['archived_at']): ?>
                <span class="badge badge--muted"><?= e(__('admin.dashboard.status_archived')) ?></span>
                <?php else: ?>
                <span class="badge badge--success"><?= e(__('admin.dashboard.status_active')) ?></span>
                <?php endif; ?>
            </td>
            <td style="width:1%">
                <?php
                $manageUrl = base_url('manage/' . $g['admin_token']);
                $groupUrl  = base_url('group/'  . $g['share_token']);
                $archiveLabel = e(__('admin.dashboard.archive'));
                $deleteLabel  = e(__('admin.dashboard.delete'));
                $archiveConfirm = e(__('admin.dashboard.archive_confirm', ['name' => $g['name']]));
                $deleteConfirm  = e(__('admin.dashboard.delete_confirm',  ['name' => $g['name']]));
                $viewTitle    = e(__('admin.dashboard.view'));
                $groupTitle   = e(__('nav.group'));
                ?>

                <!-- Desktop: icon-only buttons -->
                <div class="dash-actions">
                    <a href="<?= $manageUrl ?>" class="btn btn--ghost btn--sm"
                       title="<?= $viewTitle ?>">
                        <i class="fa-solid fa-gauge" aria-hidden="true"></i>
                    </a>
                    <a href="<?= $groupUrl ?>" class="btn btn--ghost btn--sm" target="_blank" rel="noopener"
                       title="<?= $groupTitle ?>">
                        <i class="fa-solid fa-users" aria-hidden="true"></i>
                    </a>
                    <?php if (!$g['archived_at']): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action"   value="archive">
                        <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="btn btn--ghost btn--sm" title="<?= $archiveLabel ?>"
                                data-confirm="<?= $archiveConfirm ?>">
                            <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action"   value="delete">
                        <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                        <button type="submit" class="btn btn--ghost btn--sm btn--danger" title="<?= $deleteLabel ?>"
                                data-confirm="<?= $deleteConfirm ?>">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>

                <!-- Mobile: collapsible dropdown -->
                <details class="dash-actions-menu">
                    <summary class="btn btn--ghost btn--sm" aria-label="<?= e(__('admin.dashboard.col_actions')) ?>">
                        <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                    </summary>
                    <div class="dash-actions-menu__panel">
                        <a href="<?= $manageUrl ?>" class="dash-actions-menu__item">
                            <i class="fa-solid fa-gauge" aria-hidden="true"></i> <?= $viewTitle ?>
                        </a>
                        <a href="<?= $groupUrl ?>" class="dash-actions-menu__item" target="_blank" rel="noopener">
                            <i class="fa-solid fa-users" aria-hidden="true"></i> <?= $groupTitle ?>
                        </a>
                        <?php if (!$g['archived_at']): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action"   value="archive">
                            <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                            <button type="submit" class="dash-actions-menu__form-btn"
                                    data-confirm="<?= $archiveConfirm ?>">
                                <i class="fa-solid fa-box-archive" aria-hidden="true"></i> <?= $archiveLabel ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action"   value="delete">
                            <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                            <button type="submit" class="dash-actions-menu__form-btn dash-actions-menu__form-btn--danger"
                                    data-confirm="<?= $deleteConfirm ?>">
                                <i class="fa-solid fa-trash" aria-hidden="true"></i> <?= $deleteLabel ?>
                            </button>
                        </form>
                    </div>
                </details>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var input = document.getElementById('group-search');
    if (input) {
        input.addEventListener('input', function () {
            var q = this.value.toLowerCase();
            document.querySelectorAll('#groups-table tbody tr').forEach(function (row) {
                row.style.display = row.dataset.name.includes(q) ? '' : 'none';
            });
        });
    }

})();
</script>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
