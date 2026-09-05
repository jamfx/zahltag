<?php
declare(strict_types=1);

$adminToken = $params['admin_token'] ?? '';
$group      = require_group_admin($adminToken);

$errors  = [];
$success = '';

// ─── Handle POST actions ────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'save_settings';

    // ── Save general settings ──────────────────────────────────────────────
    if ($action === 'save_settings') {
        $pinRequired          = isset($_POST['pin_required']) ? 1 : 0;
        $categoriesEnabled    = isset($_POST['categories_enabled']) ? 1 : 0;
        $categoriesRequired   = $categoriesEnabled && isset($_POST['categories_required']) ? 1 : 0;
        $showPresetCategories = $categoriesEnabled && isset($_POST['show_preset_categories']) ? 1 : 0;

        $parseMargin = static function (string $raw): ?float {
            return $raw === '' ? null : max(0.0, min(5.0, round((float)$raw, 1)));
        };
        $pdfMarginTop    = $parseMargin($_POST['pdf_margin_top']    ?? '');
        $pdfMarginRight  = $parseMargin($_POST['pdf_margin_right']  ?? '');
        $pdfMarginBottom = $parseMargin($_POST['pdf_margin_bottom'] ?? '');
        $pdfMarginLeft   = $parseMargin($_POST['pdf_margin_left']   ?? '');

        if (empty($errors)) {
            // If disabling PIN, clear all member PINs
            if ((int)$group['pin_required'] === 1 && $pinRequired === 0) {
                $stmtClear = db()->prepare('UPDATE members SET pin_hash = NULL WHERE group_id = ?');
                $stmtClear->execute([$group['id']]);
            }

            $stmtUpd = db()->prepare(
                'UPDATE groups SET pin_required = ?, categories_enabled = ?,
                 categories_required = ?, show_preset_categories = ?,
                 pdf_margin_top = ?, pdf_margin_right = ?,
                 pdf_margin_bottom = ?, pdf_margin_left = ? WHERE id = ?'
            );
            $stmtUpd->execute([
                $pinRequired, $categoriesEnabled,
                $categoriesRequired, $showPresetCategories,
                $pdfMarginTop, $pdfMarginRight,
                $pdfMarginBottom, $pdfMarginLeft, $group['id'],
            ]);

            log_activity($group['id'], null, 'settings_changed');
            flash('success', __('group.settings.save_success'));

            // Reload group data
            $group = require_group_admin($adminToken);
        }

    // ── Add custom category ────────────────────────────────────────────────
    } elseif ($action === 'add_category') {
        $catName = trim($_POST['cat_name'] ?? '');
        if ($catName === '') {
            $errors[] = __('validation.required');
        } elseif (mb_strlen($catName) > 100) {
            $errors[] = __('validation.too_long', ['max' => 100]);
        } else {
            $stmtMax = db()->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_order FROM custom_categories WHERE group_id = ?'
            );
            $stmtMax->execute([$group['id']]);
            $nextOrder = (int)$stmtMax->fetchColumn();

            $stmtIns = db()->prepare(
                'INSERT INTO custom_categories (group_id, name, sort_order) VALUES (?, ?, ?)'
            );
            $stmtIns->execute([$group['id'], $catName, $nextOrder]);
            log_activity($group['id'], null, 'category_added', ['name' => $catName]);
            flash('success', __('group.settings.category_add') . ': ' . $catName);
        }

    // ── Rename custom category ─────────────────────────────────────────────
    } elseif ($action === 'rename_category') {
        $catId      = (int)($_POST['cat_id'] ?? 0);
        $newCatName = trim($_POST['cat_name'] ?? '');

        $stmtCat = db()->prepare(
            'SELECT * FROM custom_categories WHERE id = ? AND group_id = ? LIMIT 1'
        );
        $stmtCat->execute([$catId, $group['id']]);
        $cat = $stmtCat->fetch();

        if (!$cat) {
            $errors[] = __('common.not_found');
        } elseif ($newCatName === '') {
            $errors[] = __('validation.required');
        } elseif (mb_strlen($newCatName) > 100) {
            $errors[] = __('validation.too_long', ['max' => 100]);
        } else {
            $oldName = $cat['name'];
            $stmtRen = db()->prepare('UPDATE custom_categories SET name = ? WHERE id = ? AND group_id = ?');
            $stmtRen->execute([$newCatName, $catId, $group['id']]);
            log_activity($group['id'], null, 'category_renamed', ['old' => $oldName, 'new' => $newCatName]);
            flash('success', __('group.settings.save_success'));
        }

    // ── Delete custom category ─────────────────────────────────────────────
    } elseif ($action === 'delete_category') {
        $catId = (int)($_POST['cat_id'] ?? 0);

        $stmtCat = db()->prepare(
            'SELECT * FROM custom_categories WHERE id = ? AND group_id = ? LIMIT 1'
        );
        $stmtCat->execute([$catId, $group['id']]);
        $cat = $stmtCat->fetch();

        if (!$cat) {
            $errors[] = __('common.not_found');
        } else {
            // Check if in use
            $stmtInUse = db()->prepare(
                'SELECT COUNT(*) FROM expenses WHERE category_custom_id = ? AND group_id = ?'
            );
            $stmtInUse->execute([$catId, $group['id']]);
            if ((int)$stmtInUse->fetchColumn() > 0) {
                $errors[] = __('group.settings.category_in_use');
            } else {
                $catName = $cat['name'];
                $stmtDel = db()->prepare('DELETE FROM custom_categories WHERE id = ? AND group_id = ?');
                $stmtDel->execute([$catId, $group['id']]);
                log_activity($group['id'], null, 'category_deleted', ['name' => $catName]);
                flash('success', __('common.delete') . ': ' . $catName);
            }
        }

    // ── Move category up / down ────────────────────────────────────────────
    } elseif ($action === 'move_category') {
        $catId    = (int)($_POST['cat_id'] ?? 0);
        $direction = $_POST['direction'] === 'up' ? 'up' : 'down';

        $stmtCat = db()->prepare(
            'SELECT * FROM custom_categories WHERE id = ? AND group_id = ? LIMIT 1'
        );
        $stmtCat->execute([$catId, $group['id']]);
        $cat = $stmtCat->fetch();

        if ($cat) {
            // Find the adjacent category to swap with
            if ($direction === 'up') {
                $stmtAdj = db()->prepare(
                    'SELECT * FROM custom_categories WHERE group_id = ? AND sort_order < ?
                     ORDER BY sort_order DESC LIMIT 1'
                );
            } else {
                $stmtAdj = db()->prepare(
                    'SELECT * FROM custom_categories WHERE group_id = ? AND sort_order > ?
                     ORDER BY sort_order ASC LIMIT 1'
                );
            }
            $stmtAdj->execute([$group['id'], $cat['sort_order']]);
            $adjacent = $stmtAdj->fetch();

            if ($adjacent) {
                $stmtSwap1 = db()->prepare('UPDATE custom_categories SET sort_order = ? WHERE id = ?');
                $stmtSwap2 = db()->prepare('UPDATE custom_categories SET sort_order = ? WHERE id = ?');
                $stmtSwap1->execute([$adjacent['sort_order'], $catId]);
                $stmtSwap2->execute([$cat['sort_order'], $adjacent['id']]);
            }
        }

    // ── Upload cover image ─────────────────────────────────────────────────
    } elseif ($action === 'upload_cover') {
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
            $tmp  = $_FILES['cover_image']['tmp_name'];
            $size = $_FILES['cover_image']['size'];
            $mime = mime_content_type($tmp) ?: '';

            if ($size > 2_097_152) {
                $errors[] = __('group.settings.cover_image') . ': max. 2 MB';
            } elseif (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                $errors[] = __('group.settings.cover_image') . ': ' . __('expense.validation.receipt_invalid_type');
            } else {
                $ext     = 'jpg'; // always JPEG after GD reprocessing (strips metadata)
                $destDir = BASE_PATH . '/uploads/group_covers';
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                foreach (['jpg', 'png'] as $oldExt) {
                    $old = $destDir . '/' . $group['share_token'] . '.' . $oldExt;
                    if (file_exists($old)) @unlink($old);
                }
                $dest     = $destDir . '/' . $group['share_token'] . '.' . $ext;
                $relPath  = 'uploads/group_covers/' . $group['share_token'] . '.' . $ext;
                if (process_receipt_image($tmp, $dest)) {
                    db()->prepare('UPDATE groups SET cover_image = ? WHERE id = ?')
                        ->execute([$relPath, $group['id']]);
                    flash('success', __('common.save'));
                    redirect('/manage/' . $adminToken . '/settings');
                } else {
                    $errors[] = __('group.settings.cover_image_upload_error');
                }
            }
        }

    // ── Save cover focal point ─────────────────────────────────────────────
    } elseif ($action === 'save_cover_position') {
        $allowed = ['left top', 'center top', 'right top', 'left center', 'center center', 'right center', 'left bottom', 'center bottom', 'right bottom'];
        $pos = $_POST['cover_position'] ?? 'center center';
        if (!in_array($pos, $allowed, true)) $pos = 'center center';
        db()->prepare('UPDATE groups SET cover_image_position = ? WHERE id = ?')->execute([$pos, $group['id']]);
        flash('success', __('common.save'));
        redirect('/manage/' . $adminToken . '/settings');

    // ── Delete cover image ─────────────────────────────────────────────────
    } elseif ($action === 'delete_cover') {
        if (!empty($group['cover_image'])) {
            $path = BASE_PATH . '/' . ltrim($group['cover_image'], '/');
            if (file_exists($path)) @unlink($path);
            db()->prepare('UPDATE groups SET cover_image = NULL WHERE id = ?')
                ->execute([$group['id']]);
        }
        flash('success', __('common.delete'));
        redirect('/manage/' . $adminToken . '/settings');

    // ── Archive group ──────────────────────────────────────────────────────
    } elseif ($action === 'archive_group') {
        $stmtArch = db()->prepare('UPDATE groups SET archived_at = NOW() WHERE id = ?');
        $stmtArch->execute([$group['id']]);
        log_activity($group['id'], null, 'group_archived');
        flash('success', __('group.archived'));
        redirect('/manage/' . $adminToken);

    // ── Delete group ───────────────────────────────────────────────────────
    } elseif ($action === 'delete_group') {
        $confirmedName = trim($_POST['confirm_name'] ?? '');
        if ($confirmedName !== $group['name']) {
            $errors[] = __('group.settings.delete_confirm2') . ' (' . __('common.error') . ')';
        } else {
            // Remove receipt files
            $stmtReceipts = db()->prepare(
                'SELECT receipt_path FROM expenses WHERE group_id = ? AND receipt_path IS NOT NULL'
            );
            $stmtReceipts->execute([$group['id']]);
            foreach ($stmtReceipts->fetchAll() as $r) {
                $filePath = BASE_PATH . '/' . ltrim($r['receipt_path'], '/');
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            $stmtDel = db()->prepare('DELETE FROM groups WHERE id = ?');
            $stmtDel->execute([$group['id']]);

            flash('success', __('common.delete'));
            redirect('/');
        }
    }
}

// ─── Load custom categories ──────────────────────────────────────────────────
$customCategories = [];
try {
    $stmtCats = db()->prepare(
        'SELECT * FROM custom_categories WHERE group_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $stmtCats->execute([$group['id']]);
    $customCategories = $stmtCats->fetchAll();
} catch (Throwable) {}

$shareUrl   = base_url('group/' . $group['share_token']);
$cleanupDays = (int)setting('cleanup_days', 90);

$pageTitle = e($group['name']) . ' – ' . e(__('group.settings.title'));
$navLinks  = [
    ['url' => base_url('manage/' . $adminToken),                'label' => __('nav.overview'),         'icon' => 'fa-solid fa-gauge',     'active' => false],
    ['url' => base_url('manage/' . $adminToken . '/settings'),  'label' => __('group.settings.title'), 'icon' => 'fa-solid fa-sliders',   'active' => true],
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
?>
<h1><?= e(__('group.settings.title')) ?></h1>

<?php if (!empty($errors)): ?>
<div class="flash flash--error" role="alert">
    <ul style="margin:0;padding-left:1.25rem">
        <?php foreach ($errors as $err): ?>
            <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ── Share link ──────────────────────────────────────────────────────── -->
<div class="card">
    <h2 class="form-section"><?= e(__('group.settings.share_link')) ?></h2>
    <p class="text-muted" style="font-size:0.875rem;margin-bottom:0.75rem"><?= e(__('group.settings.share_link_hint')) ?></p>
    <div class="copy-field">
        <input type="text" id="share-url" value="<?= e($shareUrl) ?>" readonly>
        <button type="button" class="btn btn--secondary btn--sm" data-copy-target="#share-url">
            <?= e(__('common.copy')) ?>
        </button>
    </div>
</div>

<!-- ── Cover image ────────────────────────────────────────────────────── -->
<div class="card">
    <h2 class="form-section"><?= e(__('group.settings.cover_image')) ?></h2>

    <?php
    $hasCover = !empty($group['cover_image']) && file_exists(BASE_PATH . '/' . ltrim($group['cover_image'], '/'));
    ?>
    <?php if ($hasCover):
        $coverPos = $group['cover_image_position'] ?? 'center center';
    ?>
    <form method="post" style="margin-bottom:1.25rem">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_cover_position">
        <input type="hidden" name="cover_position" id="focal-input" value="<?= e($coverPos) ?>">
        <p style="font-size:.875rem;font-weight:600;margin-bottom:.5rem"><?= e(__('group.settings.cover_focal_label')) ?></p>
        <div class="focal-picker" id="focal-picker">
            <img class="focal-picker__img" id="focal-img"
                 src="<?= e(base_url($group['cover_image'])) ?>" alt=""
                 style="object-position:<?= e($coverPos) ?>">
            <div class="focal-picker__grid">
                <?php
                $positions = [
                    'left top'    => __('group.settings.focal_top_left'),
                    'center top'  => __('group.settings.focal_top_center'),
                    'right top'   => __('group.settings.focal_top_right'),
                    'left center' => __('group.settings.focal_center_left'),
                    'center center' => __('group.settings.focal_center'),
                    'right center'  => __('group.settings.focal_center_right'),
                    'left bottom'   => __('group.settings.focal_bottom_left'),
                    'center bottom' => __('group.settings.focal_bottom_center'),
                    'right bottom'  => __('group.settings.focal_bottom_right'),
                ];
                foreach ($positions as $p => $pLabel): ?>
                <button type="button"
                        class="focal-dot<?= $p === $coverPos ? ' is-active' : '' ?>"
                        data-pos="<?= e($p) ?>"
                        aria-label="<?= e($pLabel) ?>"
                        aria-pressed="<?= $p === $coverPos ? 'true' : 'false' ?>"></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <button type="submit" class="btn btn--secondary btn--sm"><?= e(__('group.settings.cover_focal_save')) ?></button>
            <button type="submit" form="form-delete-cover" class="btn btn--ghost btn--sm btn--danger"
                    data-confirm="<?= e(__('group.settings.cover_image_delete_confirm')) ?>">
                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                <?= e(__('group.settings.cover_image_delete')) ?>
            </button>
        </div>
    </form>
    <form id="form-delete-cover" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete_cover">
    </form>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="upload_cover">
        <div class="form-group">
            <label for="cover_image"><?= e($hasCover ? __('group.settings.cover_image_replace') : __('group.settings.cover_image_upload')) ?></label>
            <input type="file" id="cover_image" name="cover_image" accept="image/jpeg,image/png">
            <small class="form-hint"><?= e(__('group.settings.cover_image_hint')) ?></small>
        </div>
        <button type="submit" class="btn btn--secondary btn--sm"><?= e(__('common.save')) ?></button>
    </form>
</div>

<!-- ── Send invite by email ────────────────────────────────────────── -->
<div class="card">
    <h2 class="form-section"><?= e(__('group.settings.send_invite')) ?></h2>
    <form method="post" action="<?= e(base_url('manage/' . $adminToken . '/send-invite')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-group">
            <label for="invite-emails"><?= e(__('group.settings.send_invite_label')) ?></label>
            <input type="text"
                   id="invite-emails"
                   name="emails"
                   placeholder="alice@example.com, bob@example.com"
                   autocomplete="off">
            <small class="form-hint"><?= e(__('group.settings.send_invite_hint')) ?></small>
        </div>
        <button type="submit" class="btn btn--secondary"><?= e(__('group.settings.send_invite_submit')) ?></button>
    </form>
</div>

<!-- ── General settings ───────────────────────────────────────────────── -->
<div class="card">

    <!-- Main form — wraps Zugangscode + Kategorien; PDF inputs join via form= -->
    <form id="form-save-settings" method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_settings">

        <h3 style="font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--color-text-muted);margin-bottom:.75rem"><?= e(__('member.pin_label')) ?></h3>
        <p class="form-hint" style="margin-bottom:.6rem"><?= e(__('group.settings.pin_hint')) ?></p>
        <label class="check-row">
            <input type="checkbox"
                   id="pin_required"
                   name="pin_required"
                   value="1"
                   <?= $group['pin_required'] ? 'checked' : '' ?>
                   data-confirm-disable="<?= e(__('group.settings.pin_disable_confirm')) ?>">
            <span><?= e(__('group.settings.pin_required')) ?></span>
        </label>

        <h3 class="form-section" style="margin-top:1.5rem"><?= e(__('expense.add.category')) ?></h3>
        <p class="form-hint" style="margin-bottom:.6rem"><?= e(__('group.settings.categories_hint')) ?></p>
        <label class="check-row">
            <input type="checkbox"
                   id="categories_enabled"
                   name="categories_enabled"
                   value="1"
                   <?= $group['categories_enabled'] ? 'checked' : '' ?>
                   data-toggle-target="#category-suboptions">
            <span><?= e(__('group.settings.categories_enabled')) ?></span>
        </label>
        <div id="category-suboptions"<?= $group['categories_enabled'] ? '' : ' class="hidden"' ?> style="padding-left:1.5rem;margin-top:0.5rem">
            <label class="check-row">
                <input type="checkbox" name="categories_required" value="1" <?= $group['categories_required'] ? 'checked' : '' ?>>
                <span><?= e(__('group.settings.categories_required')) ?></span>
            </label>
            <label class="check-row">
                <input type="checkbox" name="show_preset_categories" value="1" <?= $group['show_preset_categories'] ? 'checked' : '' ?>>
                <span><?= e(__('group.settings.show_preset_categories')) ?></span>
            </label>
        </div>
    </form>

    <!-- Eigene Kategorien — separate forms, gleiche Card -->
    <div style="border-top:1px solid var(--color-border);margin-top:1.5rem;padding-top:1.5rem">
        <h3 class="form-section" style="margin-top:0"><?= e(__('group.settings.custom_categories')) ?></h3>
        <p class="form-hint" style="margin-bottom:.75rem"><?= e(__('group.settings.custom_categories_hint')) ?></p>

        <?php if (!empty($customCategories)): ?>
        <ul class="category-list" style="margin-bottom:1.5rem">
            <?php foreach ($customCategories as $cat): ?>
            <li class="category-row">
                <form method="post" class="category-row__rename" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="rename_category">
                    <input type="hidden" name="cat_id" value="<?= (int)$cat['id'] ?>">
                    <input type="text"
                           name="cat_name"
                           value="<?= e($cat['name']) ?>"
                           maxlength="100"
                           required
                           aria-label="<?= e(__('group.settings.category_name_placeholder')) ?>">
                    <button type="submit" class="btn btn--ghost btn--sm"><?= e(__('common.save')) ?></button>
                </form>
                <span class="category-row__actions">
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="move_category">
                        <input type="hidden" name="cat_id" value="<?= (int)$cat['id'] ?>">
                        <input type="hidden" name="direction" value="up">
                        <button type="submit" class="btn btn--ghost btn--sm"
                                aria-label="<?= e(__('group.settings.category_move_up', ['name' => $cat['name']])) ?>">↑</button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="move_category">
                        <input type="hidden" name="cat_id" value="<?= (int)$cat['id'] ?>">
                        <input type="hidden" name="direction" value="down">
                        <button type="submit" class="btn btn--ghost btn--sm"
                                aria-label="<?= e(__('group.settings.category_move_down', ['name' => $cat['name']])) ?>">↓</button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="cat_id" value="<?= (int)$cat['id'] ?>">
                        <button type="submit"
                                class="btn btn--danger btn--sm"
                                data-confirm="<?= e(__('group.settings.category_delete_confirm')) ?>">
                            <?= e(__('common.delete')) ?>
                        </button>
                    </form>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_category">
            <div class="form-row">
                <input type="text"
                       name="cat_name"
                       placeholder="<?= e(__('group.settings.category_name_placeholder')) ?>"
                       maxlength="100"
                       required
                       style="flex:1">
                <button type="submit" class="btn btn--secondary btn--sm">
                    + <?= e(__('group.settings.category_add')) ?>
                </button>
            </div>
        </form>
    </div>

    <!-- PDF margins — inputs reference main form via form= attribute -->
    <div style="border-top:1px solid var(--color-border);margin-top:1.5rem;padding-top:1.5rem">
        <h3 class="form-section" style="margin-top:0"><?= e(__('group.settings.pdf_margins')) ?></h3>
        <p class="form-hint" style="margin-bottom:.75rem"><?= e(__('group.settings.pdf_margins_hint', [
            'top'    => setting('pdf_margin_top',    '1.0'),
            'right'  => setting('pdf_margin_right',  '1.0'),
            'bottom' => setting('pdf_margin_bottom', '1.0'),
            'left'   => setting('pdf_margin_left',   '2.5'),
        ])) ?></p>
        <div style="display:flex;gap:1rem;flex-wrap:wrap">
            <?php
            $marginFields = [
                'pdf_margin_top'    => ['group.settings.pdf_margin_top',    'pdf_margin_top',    '1.0'],
                'pdf_margin_right'  => ['group.settings.pdf_margin_right',  'pdf_margin_right',  '1.0'],
                'pdf_margin_bottom' => ['group.settings.pdf_margin_bottom', 'pdf_margin_bottom', '1.0'],
                'pdf_margin_left'   => ['group.settings.pdf_margin_left',   'pdf_margin_left',   '2.5'],
            ];
            foreach ($marginFields as $col => [$labelKey, $settingKey, $default]): ?>
            <div class="form-group" style="flex:1;min-width:110px">
                <label for="<?= $col ?>"><?= e(__($labelKey)) ?></label>
                <input type="number" id="<?= $col ?>" name="<?= $col ?>"
                       form="form-save-settings"
                       value="<?= $group[$col] !== null ? e((string)$group[$col]) : '' ?>"
                       step="0.1" min="0" max="5"
                       placeholder="<?= e(setting($settingKey, $default)) ?>">
            </div>
            <?php endforeach; ?>
        </div>
        <button type="submit" form="form-save-settings" class="btn btn--primary" style="margin-top:1.5rem">
            <?= e(__('common.save')) ?>
        </button>
    </div>

</div><!-- end card -->

<!-- ── Danger zone ────────────────────────────────────────────────────── -->
<div class="danger-zone">
    <h2><?= e(__('group.settings.archive')) ?> / <?= e(__('group.settings.delete')) ?></h2>

    <?php if (!$group['archived_at']): ?>
    <div style="margin-bottom:1.5rem">
        <p style="margin-bottom:0.75rem">
            <?= e(__('group.settings.archive_confirm', ['days' => $cleanupDays ?: '∞'])) ?>
        </p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="archive_group">
            <button type="submit"
                    class="btn btn--secondary"
                    data-confirm="<?= e(__('group.settings.archive_confirm', ['days' => $cleanupDays ?: '∞'])) ?>">
                <?= e(__('group.settings.archive')) ?>
            </button>
        </form>
    </div>
    <?php endif; ?>

    <div>
        <p style="margin-bottom:0.75rem"><?= e(__('group.settings.delete_confirm')) ?></p>
        <p style="font-size:0.875rem;margin-bottom:0.75rem"><?= e(__('group.settings.delete_confirm2')) ?></p>
        <form method="post" id="delete-group-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete_group">
            <div class="form-row">
                <input type="text"
                       name="confirm_name"
                       id="confirm-name-input"
                       placeholder="<?= e($group['name']) ?>"
                       autocomplete="off"
                       style="flex:1">
                <button type="submit"
                        id="delete-group-btn"
                        class="btn btn--danger"
                        disabled>
                    <?= e(__('group.settings.delete')) ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var nameInput  = document.getElementById('confirm-name-input');
    var deleteBtn  = document.getElementById('delete-group-btn');
    var groupName  = <?= json_encode($group['name']) ?>;
    if (nameInput && deleteBtn) {
        nameInput.addEventListener('input', function () {
            deleteBtn.disabled = this.value !== groupName;
        });
    }

    var pinCheckbox = document.getElementById('pin_required');
    if (pinCheckbox) {
        pinCheckbox.addEventListener('change', function () {
            if (!this.checked) {
                var self = this;
                var msg = self.getAttribute('data-confirm-disable');
                if (msg) {
                    self.checked = true;
                    showConfirm(msg, function () { self.checked = false; }, false);
                }
            }
        });
    }
})();
</script>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
