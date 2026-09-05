<?php
declare(strict_types=1);

$shareToken = $params['share_token'] ?? '';
$group      = get_group_by_share_token($shareToken);

if (!$group) {
    http_response_code(404);
    $pageTitle = __('common.not_found');
    ob_start();
    echo '<div class="card"><p>' . e(__('group.not_found')) . '</p></div>';
    $content = ob_get_clean();
    require BASE_PATH . '/templates/layout.php';
    exit;
}

// --- Determine current member from session ---
$currentMember = null;
if (!empty($_SESSION['member_id']) && !empty($_SESSION['group_id'])
    && (int)$_SESSION['group_id'] === (int)$group['id']) {
    try {
        $stmt = db()->prepare(
            'SELECT * FROM members WHERE id = ? AND group_id = ? AND active = 1 LIMIT 1'
        );
        $stmt->execute([$_SESSION['member_id'], $group['id']]);
        $currentMember = $stmt->fetch() ?: null;
    } catch (Throwable) {
        $currentMember = null;
    }
}

// Handle sign-out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    verify_csrf();
    unset($_SESSION['member_id'], $_SESSION['group_id']);
    redirect('/group/' . $shareToken);
}

// --- Load existing members for dropdown ---
$existingMembers = [];
try {
    $stmtM = db()->prepare(
        'SELECT id, name, pin_hash FROM members WHERE group_id = ? AND active = 1 ORDER BY name'
    );
    $stmtM->execute([$group['id']]);
    $existingMembers = $stmtM->fetchAll();
} catch (Throwable) {
    $existingMembers = [];
}

// --- Join / sign-in POST handling ---
$errors   = [];
$formData = [];

if ($currentMember === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? 'new';

    if ($action === 'new') {
        $name = trim($_POST['name'] ?? '');
        $pin  = $_POST['pin'] ?? '';
        $pin2 = $_POST['pin2'] ?? '';
        $formData = ['name' => $name, 'action' => 'new'];

        if ($name === '') {
            $errors[] = __('member.error.name_empty');
        } elseif (mb_strlen($name) > 100) {
            $errors[] = __('member.error.name_too_long');
        } else {
            $stmtDup = db()->prepare(
                'SELECT id FROM members WHERE group_id = ? AND LOWER(name) = LOWER(?) LIMIT 1'
            );
            $stmtDup->execute([$group['id'], $name]);
            if ($stmtDup->fetch()) {
                $errors[] = __('member.error.name_duplicate');
            }
        }

        if ($group['pin_required']) {
            $pinLen = mb_strlen(trim($pin));
            if ($pinLen < 4 || $pinLen > 12) {
                $errors[] = __('member.error.pin_too_short');
            } elseif ($pin !== $pin2) {
                $errors[] = __('member.error.pin_mismatch');
            }
        }

        if (empty($errors)) {
            $pinHash = $group['pin_required'] ? password_hash($pin, PASSWORD_BCRYPT) : null;
            $stmtIns = db()->prepare(
                'INSERT INTO members (group_id, name, pin_hash) VALUES (?, ?, ?)'
            );
            $stmtIns->execute([$group['id'], $name, $pinHash]);
            $newId = (int)db()->lastInsertId();

            $_SESSION['member_id'] = $newId;
            $_SESSION['group_id']  = $group['id'];

            log_activity($group['id'], $newId, 'member_joined', ['name' => $name]);
            redirect('/group/' . $shareToken);
        }

    } elseif ($action === 'existing') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $pin     = $_POST['pin']     ?? '';   // verify existing code
        $pinNew  = $_POST['pin_new'] ?? '';   // set new code (first-time setup)
        $pin2New = $_POST['pin2_new'] ?? '';  // confirm new code
        $formData = ['action' => 'existing', 'member_id' => $memberId];

        $foundMember = null;
        foreach ($existingMembers as $m) {
            if ((int)$m['id'] === $memberId) {
                $foundMember = $m;
                break;
            }
        }

        if (!$foundMember) {
            $errors[] = __('member.error.member_not_found');
        } elseif ($group['pin_required']) {
            if ($foundMember['pin_hash'] === null) {
                // First-time setup: member has no code yet
                $pinLen = mb_strlen(trim($pinNew));
                if ($pinLen < 4 || $pinLen > 12) {
                    $errors[] = __('member.error.pin_too_short');
                } elseif ($pinNew !== $pin2New) {
                    $errors[] = __('member.error.pin_mismatch');
                } else {
                    $pinHash = password_hash($pinNew, PASSWORD_BCRYPT);
                    $stmtUpd = db()->prepare('UPDATE members SET pin_hash = ? WHERE id = ? AND group_id = ?');
                    $stmtUpd->execute([$pinHash, $memberId, $group['id']]);
                }
            } else {
                // Verify: member already has a code
                if (!password_verify($pin, $foundMember['pin_hash'])) {
                    $errors[] = __('member.error.pin_wrong');
                }
            }
        }

        if (empty($errors) && $foundMember) {
            $_SESSION['member_id'] = $memberId;
            $_SESSION['group_id']  = $group['id'];
            redirect('/group/' . $shareToken);
        }
    }
}

// --- Render ---
$isArchived  = $group['archived_at'] !== null;
$pageTitle   = $group['name'];
$memberCount = count($existingMembers);

$navLinks = [];
if ($currentMember) {
    $navLinks = [
        ['url' => base_url('group/' . $shareToken),                  'label' => __('nav.expenses'), 'icon' => 'fa-solid fa-receipt',         'active' => true],
        ['url' => base_url('group/' . $shareToken . '/settle'),       'label' => __('nav.settle'),   'icon' => 'fa-solid fa-scale-balanced',  'active' => false],
        ['url' => base_url('group/' . $shareToken . '/payment-data'), 'label' => __('group.view.my_payment_data'), 'icon' => 'fa-solid fa-wallet', 'active' => false],
    ];
}

ob_start();

// === ARCHIVED NOTICE ===
if ($isArchived): ?>
<div class="flash flash--warning" role="alert">
    <span><?= e(__('group.archived_notice')) ?></span>
</div>
<?php endif;

// ============================================================
// JOIN FORM – shown when no member is in session
// ============================================================
if ($currentMember === null):
?>
<?php if (!empty($group['cover_image']) && file_exists(BASE_PATH . '/' . ltrim($group['cover_image'], '/'))): ?>
<div class="group-banner">
    <img src="<?= e(base_url($group['cover_image'])) ?>" alt=""
         style="object-position:<?= e($group['cover_image_position'] ?? 'center center') ?>">
</div>
<?php endif; ?>
<div class="card" style="max-width:540px;margin:2rem auto">
    <p class="text-muted" style="margin-bottom:0.25rem"><?= e(__('group.view.invited_to')) ?></p>
    <h1 style="margin-bottom:1.5rem"><?= e($group['name']) ?></h1>

    <?php if (!empty($errors)): ?>
    <div class="flash flash--error" role="alert">
        <ul style="margin:0;padding-left:1.25rem">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php
    $selectedMemberId = (int)($formData['member_id'] ?? 0);
    $selectedMember   = null;
    foreach ($existingMembers as $m) {
        if ((int)$m['id'] === $selectedMemberId) { $selectedMember = $m; break; }
    }
    $needsPinSetup = $selectedMember && $group['pin_required'] && $selectedMember['pin_hash'] === null;
    ?>

    <!-- NEW MEMBER FORM -->
    <section aria-labelledby="new-member-heading">
        <h2 id="new-member-heading" style="font-size:1rem;margin-bottom:1rem"><?= e(__('member.join_title')) ?></h2>
        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="new">

            <div class="form-group">
                <label for="name"><?= e(__('member.your_name')) ?> *</label>
                <input type="text" id="name" name="name"
                       value="<?= e(($formData['action'] ?? '') === 'new' ? ($formData['name'] ?? '') : '') ?>"
                       placeholder="<?= e(__('member.your_name_placeholder')) ?>"
                       maxlength="100" autocomplete="given-name" required>
            </div>

            <?php if ($group['pin_required']): ?>
            <div class="form-group">
                <label for="pin"><?= e(__('member.pin_label')) ?></label>
                <input type="password" id="pin" name="pin"
                       minlength="4" maxlength="12" autocomplete="new-password">
                <small class="form-hint"><?= e(__('member.pin_hint')) ?></small>
            </div>
            <div class="form-group">
                <label for="pin2"><?= e(__('member.pin_confirm_label')) ?></label>
                <input type="password" id="pin2" name="pin2"
                       minlength="4" maxlength="12" autocomplete="new-password">
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn--primary btn--full"><?= e(__('member.submit_new')) ?></button>
        </form>
    </section>

    <?php if (!empty($existingMembers)): ?>
    <hr style="margin:2rem 0;border:none;border-top:1px solid var(--color-border)">
    <section aria-labelledby="existing-member-heading">
        <h2 id="existing-member-heading" style="font-size:1rem;margin-bottom:0.5rem"><?= e(__('member.choose_name')) ?></h2>
        <p class="text-muted" style="font-size:0.875rem;margin-bottom:1rem"><?= e(__('member.choose_name_hint')) ?></p>

        <form method="post" novalidate id="existing-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="existing">

            <div class="form-group">
                <label for="member_id"><?= e(__('member.your_name')) ?></label>
                <select id="member_id" name="member_id" required>
                    <option value=""><?= e(__('common.none')) ?>…</option>
                    <?php foreach ($existingMembers as $m): ?>
                    <option value="<?= (int)$m['id'] ?>"
                            data-has-pin="<?= $m['pin_hash'] !== null ? '1' : '0' ?>"
                            <?= (int)$m['id'] === $selectedMemberId ? ' selected' : '' ?>>
                        <?= e($m['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($group['pin_required']): ?>
            <div id="pin-verify-section" class="form-group<?= ($needsPinSetup || !$selectedMemberId) ? ' hidden' : '' ?>">
                <p class="text-muted" style="font-size:0.875rem;margin-bottom:0.5rem"><?= e(__('member.pin_enter_text')) ?></p>
                <label for="existing-pin"><?= e(__('member.pin_label')) ?></label>
                <input type="password" id="existing-pin" name="pin"
                       minlength="4" maxlength="12" autocomplete="current-password">
            </div>
            <div id="pin-setup-section"<?= $needsPinSetup ? '' : ' class="hidden"' ?>>
                <p style="font-size:0.875rem;margin-bottom:0.75rem"><?= e(__('member.pin_set_text')) ?></p>
                <div class="form-group">
                    <label for="existing-pin-new"><?= e(__('member.pin_label')) ?></label>
                    <input type="password" id="existing-pin-new" name="pin_new"
                           minlength="4" maxlength="12" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="existing-pin2"><?= e(__('member.pin_confirm_label')) ?></label>
                    <input type="password" id="existing-pin2" name="pin2_new"
                           minlength="4" maxlength="12" autocomplete="new-password">
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn--secondary btn--full"><?= e(__('member.submit_existing')) ?></button>
        </form>
    </section>
    <?php endif; ?>
</div>

<?php if ($group['pin_required']): ?>
<script>
(function () {
    var select    = document.getElementById('member_id');
    var verifyDiv = document.getElementById('pin-verify-section');
    var setupDiv  = document.getElementById('pin-setup-section');
    if (!select) return;
    function updatePinFields() {
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) {
            verifyDiv && (verifyDiv.className = 'form-group hidden');
            setupDiv  && setupDiv.classList.add('hidden');
            return;
        }
        var hasPin = opt.getAttribute('data-has-pin') === '1';
        if (verifyDiv) verifyDiv.className = hasPin ? 'form-group' : 'form-group hidden';
        if (setupDiv)  setupDiv.className  = hasPin ? 'hidden' : '';
    }
    select.addEventListener('change', updatePinFields);
    updatePinFields();
})();
</script>
<?php endif; ?>

<?php
// ============================================================
// MEMBER VIEW – shown when member is logged in
// ============================================================
else:
    $categoriesEnabled = (bool)(int)$group['categories_enabled'];
    $showPresets       = $categoriesEnabled && (bool)(int)$group['show_preset_categories'];
    $presetCategories  = [
        'cat_accommodation', 'cat_food', 'cat_transport',
        'cat_activities', 'cat_shopping', 'cat_fees',
        'cat_communication', 'cat_other',
    ];

    $customCategories = [];
    if ($categoriesEnabled) {
        try {
            $stmtC = db()->prepare('SELECT id, name FROM custom_categories WHERE group_id = ? ORDER BY sort_order, id');
            $stmtC->execute([$group['id']]);
            $customCategories = $stmtC->fetchAll();
        } catch (Throwable) {}
    }

    // --- Filters from GET ---
    $filterMember   = (int)($_GET['filter_member']    ?? 0);
    $filterCat      = trim($_GET['filter_category']   ?? '');
    $filterDateFrom = trim($_GET['filter_date_from']  ?? '');
    $filterDateTo   = trim($_GET['filter_date_to']    ?? '');

    // Build expense query with optional filters
    $expWhere  = ['e.group_id = ?'];
    $expParams = [$group['id']];

    if ($filterMember > 0) {
        $expWhere[]  = 'e.paid_by = ?';
        $expParams[] = $filterMember;
    }
    if ($filterCat !== '') {
        if (str_starts_with($filterCat, 'preset:')) {
            $expWhere[]  = 'e.category_preset = ?';
            $expParams[] = substr($filterCat, 7);
        } elseif (str_starts_with($filterCat, 'custom:')) {
            $expWhere[]  = 'e.category_custom_id = ?';
            $expParams[] = (int)substr($filterCat, 7);
        }
    }
    if ($filterDateFrom !== '') {
        $expWhere[]  = 'e.expense_date >= ?';
        $expParams[] = $filterDateFrom;
    }
    if ($filterDateTo !== '') {
        $expWhere[]  = 'e.expense_date <= ?';
        $expParams[] = $filterDateTo;
    }

    $whereClause = implode(' AND ', $expWhere);

    $expenses = [];
    try {
        $stmtExp = db()->prepare(
            "SELECT e.*, m.name AS paid_by_name
             FROM expenses e
             JOIN members m ON m.id = e.paid_by
             WHERE {$whereClause}
             ORDER BY e.expense_date DESC, e.created_at DESC"
        );
        $stmtExp->execute($expParams);
        $expenses = $stmtExp->fetchAll();
    } catch (Throwable) {}

    // Load splits for displayed expenses
    $splitsByExpense = [];
    if (!empty($expenses)) {
        $expIds = array_map(fn($e) => (int)$e['id'], $expenses);
        $inPlaceholders = implode(',', array_fill(0, count($expIds), '?'));
        try {
            $stmtSpl = db()->prepare(
                "SELECT es.expense_id, es.member_id, es.share_amount, m.name AS member_name
                 FROM expense_splits es
                 JOIN members m ON m.id = es.member_id
                 WHERE es.expense_id IN ({$inPlaceholders})"
            );
            $stmtSpl->execute($expIds);
            foreach ($stmtSpl->fetchAll() as $row) {
                $splitsByExpense[(int)$row['expense_id']][] = $row;
            }
        } catch (Throwable) {}
    }

    // Unfiltered total for stats
    $totalAmountUnfiltered = 0.0;
    try {
        $stmtTot = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE group_id = ?');
        $stmtTot->execute([$group['id']]);
        $totalAmountUnfiltered = (float)$stmtTot->fetchColumn();
    } catch (Throwable) {}

    $totalAmountFiltered = array_sum(array_map(fn($e) => (float)$e['amount'], $expenses));

    // Recent activity
    $recentActivity = [];
    try {
        $stmtAct = db()->prepare(
            'SELECT al.*, m.name AS member_name
             FROM activity_log al
             LEFT JOIN members m ON m.id = al.member_id
             WHERE al.group_id = ?
             ORDER BY al.created_at DESC
             LIMIT 10'
        );
        $stmtAct->execute([$group['id']]);
        $recentActivity = $stmtAct->fetchAll();
    } catch (Throwable) {}

    $hasFilters = $filterMember || $filterCat !== '' || $filterDateFrom !== '' || $filterDateTo !== '';
?>
<!-- Signed-in header bar -->
<div class="member-bar">
    <span><?= e(__('group.view.signed_in_as', ['name' => $currentMember['name']])) ?></span>
    <div style="display:flex;gap:.5rem;align-items:center">
        <?php if (!empty($_SESSION['admin_group_id']) && (int)$_SESSION['admin_group_id'] === (int)$group['id']): ?>
        <a href="<?= base_url('manage/' . $group['admin_token']) ?>" class="btn btn--ghost btn--sm"><?= e(__('group.view.group_admin_link')) ?></a>
        <?php endif; ?>
        <form method="post" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn btn--ghost btn--sm"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> <?= e(__('group.view.sign_out')) ?></button>
        </form>
    </div>
</div>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem">
    <h1><?= e($group['name']) ?></h1>
    <?php if (!$isArchived): ?>
    <a href="<?= base_url('group/' . $shareToken . '/expense/add') ?>" class="btn btn--primary">
        <i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(__('group.view.add_expense')) ?>
    </a>
    <?php endif; ?>
</div>

<?php if (!empty($group['cover_image']) && file_exists(BASE_PATH . '/' . ltrim($group['cover_image'], '/'))): ?>
<div class="group-banner">
    <img src="<?= e(base_url($group['cover_image'])) ?>" alt=""
         style="object-position:<?= e($group['cover_image_position'] ?? 'center center') ?>">
</div>
<?php endif; ?>

<!-- Stats -->
<div class="stat-grid">
    <div class="stat-card">
        <p class="stat-card__value"><?= count($expenses) ?></p>
        <p class="stat-card__label"><?= e(__('expense.count', ['count' => count($expenses)])) ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-card__value"><?= e(format_currency($totalAmountFiltered, $group['currency'])) ?></p>
        <p class="stat-card__label"><?= e(__('group.view.total')) ?><?= $hasFilters ? ' *' : '' ?></p>
    </div>
    <div class="stat-card">
        <p class="stat-card__value"><?= $memberCount ?></p>
        <p class="stat-card__label"><?= e(__('member.count', ['count' => $memberCount])) ?></p>
    </div>
</div>

<!-- Filter bar -->
<details class="card" style="margin-bottom:1rem" <?= $hasFilters ? 'open' : '' ?>>
    <summary style="cursor:pointer;font-weight:600;padding:.25rem 0">
        <?= e(__('expense.filter.title')) ?>
        <?php if ($hasFilters): ?>
        <span class="badge badge--info" style="margin-left:.5rem"><?= e(__('expense.filter.active')) ?></span>
        <?php endif; ?>
    </summary>
    <form method="get" style="margin-top:1rem">
        <div class="form-row" style="flex-wrap:wrap;gap:.75rem">
            <div class="form-group" style="flex:1;min-width:150px">
                <label for="filter_member"><?= e(__('expense.filter.member')) ?></label>
                <select id="filter_member" name="filter_member">
                    <option value=""><?= e(__('common.all')) ?></option>
                    <?php foreach ($existingMembers as $m): ?>
                    <option value="<?= (int)$m['id'] ?>"<?= $filterMember === (int)$m['id'] ? ' selected' : '' ?>>
                        <?= e($m['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($categoriesEnabled): ?>
            <div class="form-group" style="flex:1;min-width:150px">
                <label for="filter_category"><?= e(__('expense.filter.category')) ?></label>
                <select id="filter_category" name="filter_category">
                    <option value=""><?= e(__('common.all')) ?></option>
                    <?php if ($showPresets): ?>
                    <optgroup label="—">
                        <?php foreach ($presetCategories as $key): ?>
                        <option value="preset:<?= e($key) ?>"<?= $filterCat === 'preset:' . $key ? ' selected' : '' ?>>
                            <?= e(__('expense.categories.' . $key)) ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php if (!empty($customCategories)): ?>
                    <optgroup label="—">
                        <?php foreach ($customCategories as $cat): ?>
                        <option value="custom:<?= (int)$cat['id'] ?>"<?= $filterCat === 'custom:' . $cat['id'] ? ' selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group" style="flex:1;min-width:130px">
                <label for="filter_date_from"><?= e(__('expense.filter.date_from')) ?></label>
                <input type="date" id="filter_date_from" name="filter_date_from" value="<?= e($filterDateFrom) ?>">
            </div>
            <div class="form-group" style="flex:1;min-width:130px">
                <label for="filter_date_to"><?= e(__('expense.filter.date_to')) ?></label>
                <input type="date" id="filter_date_to" name="filter_date_to" value="<?= e($filterDateTo) ?>">
            </div>
        </div>
        <div style="display:flex;gap:.5rem">
            <button type="submit" class="btn btn--secondary btn--sm"><?= e(__('expense.filter.apply')) ?></button>
            <?php if ($hasFilters): ?>
            <a href="<?= base_url('group/' . $shareToken) ?>" class="btn btn--ghost btn--sm"><?= e(__('expense.filter.reset')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</details>

<!-- Expense list -->
<div class="card">
    <h2><?= e(__('nav.expenses')) ?></h2>
    <?php if (empty($expenses)): ?>
        <p class="text-muted"><?= e($hasFilters ? __('expense.filter.no_results') : __('group.view.no_expenses')) ?></p>
        <?php if (!$isArchived && !$hasFilters): ?>
        <a href="<?= base_url('group/' . $shareToken . '/expense/add') ?>" class="btn btn--secondary btn--sm" style="margin-top:.75rem">
            <i class="fa-solid fa-plus" aria-hidden="true"></i> <?= e(__('group.view.add_expense')) ?>
        </a>
        <?php endif; ?>
    <?php else: ?>
    <?php
    $memberPalette = [
        'rgba(59,130,246,0.07)',
        'rgba(16,185,129,0.07)',
        'rgba(245,158,11,0.07)',
        'rgba(239,68,68,0.07)',
        'rgba(168,85,247,0.07)',
        'rgba(14,165,233,0.07)',
        'rgba(249,115,22,0.07)',
        'rgba(236,72,153,0.07)',
    ];
    $memberColors = [];
    $colorIdx = 0;
    foreach ($expenses as $exp) {
        $mid = (int)$exp['paid_by'];
        if (!isset($memberColors[$mid])) {
            $memberColors[$mid] = $memberPalette[$colorIdx % count($memberPalette)];
            $colorIdx++;
        }
    }
    ?>
    <div style="overflow-x:auto">
    <table class="table" style="min-width:520px">
        <thead>
            <tr>
                <th scope="col"><?= e(__('expense.list.date')) ?></th>
                <th scope="col"><?= e(__('expense.list.description')) ?></th>
                <th scope="col"><?= e(__('expense.list.paid_by')) ?></th>
                <th scope="col" class="text-right"><?= e(__('expense.list.amount')) ?></th>
                <th scope="col"><?= e(__('expense.list.split')) ?></th>
                <th scope="col" style="width:1%"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($expenses as $exp): ?>
        <?php
            $isOwner = (int)$exp['paid_by'] === (int)$currentMember['id'];
            $splits  = $splitsByExpense[(int)$exp['id']] ?? [];
            $mySplit = null;
            foreach ($splits as $s) {
                if ((int)$s['member_id'] === (int)$currentMember['id']) {
                    $mySplit = $s;
                    break;
                }
            }
        ?>
        <tr style="background:<?= $memberColors[(int)$exp['paid_by']] ?? 'transparent' ?>">
            <td style="white-space:nowrap"><?= e(format_date($exp['expense_date'])) ?></td>
            <td>
                <?= e($exp['description']) ?>
                <?php if ($exp['receipt_path']): ?>
                <?php if (str_ends_with(strtolower((string)$exp['receipt_path']), '.pdf')): ?>
                <a href="<?= e(base_url('api/receipt/' . (int)$exp['id'])) ?>"
                   class="btn btn--ghost btn--sm"
                   style="margin-left:.5rem;font-size:.75rem"
                   target="_blank" rel="noopener noreferrer"
                   aria-label="<?= e(__('expense.list.view_receipt')) ?>"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i></a>
                <?php else: ?>
                <button type="button"
                        class="btn btn--ghost btn--sm"
                        style="margin-left:.5rem;font-size:.75rem"
                        data-lightbox-src="<?= e(base_url('api/receipt/' . (int)$exp['id'])) ?>"
                        data-lightbox-label="<?= e($exp['receipt_number'] ?? $exp['description']) ?>"
                        aria-label="<?= e(__('expense.list.view_receipt')) ?>">
                    <i class="fa-solid fa-image" aria-hidden="true"></i>
                </button>
                <?php endif; ?>
                <?php endif; ?>
            </td>
            <td><?= e($exp['paid_by_name']) ?></td>
            <td class="text-right" style="white-space:nowrap">
                <?= e(format_currency((float)$exp['amount'], $exp['currency'])) ?>
                <?php if ($exp['currency'] !== $group['currency'] && $exp['exchange_rate']): ?>
                <br><small class="text-muted">= <?= e(format_currency((float)$exp['amount'], $group['currency'])) ?></small>
                <?php endif; ?>
            </td>
            <td style="font-size:.875rem">
                <?php if ($mySplit): ?>
                <span><?= e(format_currency((float)$mySplit['share_amount'], $group['currency'])) ?></span>
                <?php elseif (!empty($splits)): ?>
                <span class="text-muted">–</span>
                <?php endif; ?>
            </td>
            <td style="width:1%">
                <?php if ($isOwner && !$isArchived): ?>
                <?php
                $expEditUrl     = base_url('group/' . $shareToken . '/expense/' . (int)$exp['id'] . '/edit');
                $expDeleteUrl   = base_url('group/' . $shareToken . '/expense/' . (int)$exp['id'] . '/delete');
                $expEditLabel   = e(__('common.edit'));
                $expDeleteLabel = e(__('common.delete'));
                $expDeleteConfirm = e(__('expense.delete.confirm', ['description' => $exp['description']]));
                $expCsrf = e(csrf_token());
                ?>
                <!-- Desktop: icon-only -->
                <div class="dash-actions">
                    <a href="<?= $expEditUrl ?>" class="btn btn--ghost btn--sm" title="<?= $expEditLabel ?>">
                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                    </a>
                    <form method="post" action="<?= $expDeleteUrl ?>" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= $expCsrf ?>">
                        <button type="submit" class="btn btn--ghost btn--sm btn--danger" title="<?= $expDeleteLabel ?>"
                                data-confirm="<?= $expDeleteConfirm ?>">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                        </button>
                    </form>
                </div>
                <!-- Mobile: dropdown -->
                <details class="dash-actions-menu">
                    <summary class="btn btn--ghost btn--sm"
                             aria-label="<?= e(__('common.actions')) ?>">
                        <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                    </summary>
                    <div class="dash-actions-menu__panel">
                        <a href="<?= $expEditUrl ?>" class="dash-actions-menu__item">
                            <i class="fa-solid fa-pen" aria-hidden="true"></i> <?= $expEditLabel ?>
                        </a>
                        <form method="post" action="<?= $expDeleteUrl ?>">
                            <input type="hidden" name="csrf_token" value="<?= $expCsrf ?>">
                            <button type="submit" class="dash-actions-menu__form-btn dash-actions-menu__form-btn--danger"
                                    data-confirm="<?= $expDeleteConfirm ?>">
                                <i class="fa-solid fa-trash" aria-hidden="true"></i> <?= $expDeleteLabel ?>
                            </button>
                        </form>
                    </div>
                </details>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3"><strong><?= e(__('expense.list.total')) ?></strong></td>
                <td class="text-right"><strong><?= e(format_currency($totalAmountFiltered, $group['currency'])) ?></strong></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- Quick links -->
<div class="form-row" style="margin-top:1rem">
    <a href="<?= base_url('group/' . $shareToken . '/settle') ?>" class="btn btn--secondary">
        <i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> <?= e(__('nav.settle')) ?>
    </a>
    <a href="<?= base_url('manage/' . $group['admin_token'] . '/export/pdf') ?>" class="btn btn--ghost">
        <i class="fa-solid fa-file-pdf" aria-hidden="true"></i> <?= e(__('export.pdf')) ?>
    </a>
</div>

<!-- Activity log -->
<?php if (!empty($recentActivity)): ?>
<div class="card" style="margin-top:2rem">
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
<?php endif; ?>

<!-- Receipt lightbox -->
<div id="lightbox-overlay" aria-hidden="true" role="dialog" aria-modal="true"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;flex-direction:column">
    <button id="lightbox-close" aria-label="<?= e(__('common.close')) ?>"
            style="position:fixed;top:1rem;right:1rem;background:none;border:none;color:#fff;font-size:2rem;cursor:pointer;line-height:1">&times;</button>
    <img id="lightbox-img" src="" alt="" style="max-width:90vw;max-height:85vh;object-fit:contain;border-radius:4px">
    <p id="lightbox-label" style="color:#ddd;margin-top:.75rem;font-size:.875rem"></p>
</div>
<script>
(function () {
    var overlay  = document.getElementById('lightbox-overlay');
    var img      = document.getElementById('lightbox-img');
    var label    = document.getElementById('lightbox-label');
    var closeBtn = document.getElementById('lightbox-close');
    if (!overlay || !img) return;
    var _lbTrigger   = null;
    var _removeLbTrap = null;
    function openLightbox(src, alt, trigger) {
        img.src = src;
        img.alt = alt || '';
        if (label) label.textContent = alt || '';
        _lbTrigger = trigger || null;
        overlay.style.display = 'flex';
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        _removeLbTrap = trapFocus(overlay);
        setTimeout(function () { closeBtn && closeBtn.focus(); }, 0);
    }
    function closeLightbox() {
        overlay.style.display = 'none';
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        img.src = '';
        if (_removeLbTrap) { _removeLbTrap(); _removeLbTrap = null; }
        if (_lbTrigger && _lbTrigger.focus) _lbTrigger.focus();
    }
    document.querySelectorAll('[data-lightbox-src]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openLightbox(this.dataset.lightboxSrc, this.dataset.lightboxLabel, this);
        });
    });
    closeBtn && closeBtn.addEventListener('click', closeLightbox);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeLightbox();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.style.display !== 'none') closeLightbox();
    });
})();
</script>

<?php
endif; // end member view

$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
