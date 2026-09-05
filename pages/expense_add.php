<?php
declare(strict_types=1);

$shareToken    = $params['share_token'] ?? '';
$currentMember = require_member($shareToken);
$group         = get_group_by_share_token($shareToken);

if (!$group || $group['archived_at'] !== null) {
    http_response_code(404);
    exit;
}

$multiCurrency      = (bool)(int)setting('multi_currency_enabled', '0');
$maxReceiptMb       = max(1, (int)setting('max_receipt_size_mb', 5));
$categoriesEnabled  = (bool)(int)$group['categories_enabled'];
$categoriesRequired = $categoriesEnabled && (bool)(int)$group['categories_required'];
$showPresets        = $categoriesEnabled && (bool)(int)$group['show_preset_categories'];

// Load active members (with default_weight)
$allMembers = [];
$memberDefaultWeights = [];
try {
    $stmtM = db()->prepare(
        'SELECT id, name, default_weight FROM members WHERE group_id = ? AND active = 1 ORDER BY name'
    );
    $stmtM->execute([$group['id']]);
    $allMembers = $stmtM->fetchAll();
    foreach ($allMembers as $m) {
        $memberDefaultWeights[(int)$m['id']] = max(0.01, (float)($m['default_weight'] ?? 1.0));
    }
} catch (Throwable) {}

// Load custom categories
$customCategories = [];
if ($categoriesEnabled) {
    try {
        $stmtC = db()->prepare(
            'SELECT id, name FROM custom_categories WHERE group_id = ? ORDER BY sort_order, id'
        );
        $stmtC->execute([$group['id']]);
        $customCategories = $stmtC->fetchAll();
    } catch (Throwable) {}
}

$presetCategories = [
    'cat_accommodation', 'cat_food', 'cat_transport',
    'cat_activities', 'cat_shopping', 'cat_fees',
    'cat_communication', 'cat_other',
];

$currencies = ['EUR', 'USD', 'GBP', 'CHF', 'JPY', 'CNY'];

// ─── Default form data ────────────────────────────────────────────────────────
$errors   = [];
$formData = [
    'paid_by'              => (int)$currentMember['id'],
    'description'          => '',
    'amount'               => '',
    'currency'             => $group['currency'],
    'expense_date'         => date('Y-m-d'),
    'category_preset'      => '',
    'category_custom_id'   => '',
    'split_mode'           => 'equal',
    'split_members'        => array_column($allMembers, 'id'),
    'split_weights'        => $memberDefaultWeights,
    'split_amounts'        => [],
    'exchange_rate_manual' => '',
    'show_rate_field'      => false,
];

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $paidBy            = (int)($_POST['paid_by'] ?? $currentMember['id']);
    $description       = trim($_POST['description'] ?? '');
    $amountRaw         = trim($_POST['amount'] ?? '');
    $currency          = strtoupper(trim($_POST['currency'] ?? $group['currency']));
    $expenseDate       = trim($_POST['expense_date'] ?? '');
    $categoryPreset    = trim($_POST['category_preset'] ?? '');
    $categoryCustomId  = (int)($_POST['category_custom_id'] ?? 0);
    $splitMode         = in_array($_POST['split_mode'] ?? '', ['equal', 'weighted', 'custom'], true)
                         ? $_POST['split_mode'] : 'equal';
    $selectedMemberIds = array_map('intval', $_POST['split_members'] ?? []);
    $splitAmountsPost  = $_POST['split_amounts'] ?? [];
    $splitWeightsPost  = $_POST['split_weights'] ?? [];
    $manualRateRaw     = trim($_POST['exchange_rate_manual'] ?? '');

    // Build posted weights (fall back to default_weight for any missing member)
    $postedWeights = [];
    foreach ($selectedMemberIds as $mid) {
        $w = isset($splitWeightsPost[$mid]) ? parse_amount((string)$splitWeightsPost[$mid]) : 0.0;
        $postedWeights[$mid] = max(0.01, $w > 0 ? $w : ($memberDefaultWeights[$mid] ?? 1.0));
    }

    $formData = [
        'paid_by'              => $paidBy,
        'description'          => $description,
        'amount'               => $amountRaw,
        'currency'             => $currency,
        'expense_date'         => $expenseDate,
        'category_preset'      => $categoryPreset,
        'category_custom_id'   => $categoryCustomId ?: '',
        'split_mode'           => $splitMode,
        'split_members'        => $selectedMemberIds,
        'split_weights'        => $postedWeights + $memberDefaultWeights,
        'split_amounts'        => $splitAmountsPost,
        'exchange_rate_manual' => $manualRateRaw,
        'show_rate_field'      => false,
    ];

    // ── Validate ──────────────────────────────────────────────────────────────
    if ($description === '') {
        $errors[] = __('expense.validation.description_required');
    } elseif (mb_strlen($description) > 255) {
        $errors[] = __('expense.validation.description_too_long');
    }

    $amount = 0.0;
    if ($amountRaw === '') {
        $errors[] = __('expense.validation.amount_required');
    } else {
        $amount = parse_amount($amountRaw);
        if ($amount <= 0) {
            $errors[] = __('expense.validation.amount_zero');
        }
    }

    if ($expenseDate === '') {
        $errors[] = __('expense.validation.date_required');
    } elseif ($expenseDate > date('Y-m-d')) {
        $errors[] = __('expense.validation.date_future');
    }

    if (!in_array($currency, $currencies, true)) {
        $currency = $group['currency'];
    }

    // Verify paid_by belongs to group
    $validMemberIds = array_column($allMembers, 'id');
    if (!in_array($paidBy, array_map('intval', $validMemberIds), true)) {
        $paidBy = (int)$currentMember['id'];
    }

    // Category validation
    if ($categoriesEnabled && $categoriesRequired) {
        if ($categoryPreset === '' && $categoryCustomId === 0) {
            $errors[] = __('expense.validation.category_required');
        }
    }
    if ($categoryPreset !== '' && !in_array($categoryPreset, $presetCategories, true)) {
        $categoryPreset = '';
    }
    if ($categoryCustomId > 0) {
        $stmtCatCheck = db()->prepare(
            'SELECT id FROM custom_categories WHERE id = ? AND group_id = ? LIMIT 1'
        );
        $stmtCatCheck->execute([$categoryCustomId, $group['id']]);
        if (!$stmtCatCheck->fetch()) {
            $categoryCustomId = 0;
        }
    }

    // Split validation
    if (empty($selectedMemberIds)) {
        $errors[] = __('expense.validation.split_no_members');
    } else {
        // Ensure all selected IDs are valid group members
        $selectedMemberIds = array_values(
            array_filter($selectedMemberIds, fn($id) => in_array($id, array_map('intval', $validMemberIds), true))
        );
        if (empty($selectedMemberIds)) {
            $errors[] = __('expense.validation.split_no_members');
        }
    }

    $splitAmounts  = [];
    $splitWeights  = [];
    if (empty($errors) && $amount > 0) {
        if ($splitMode === 'custom') {
            foreach ($selectedMemberIds as $mid) {
                $splitAmounts[$mid] = round(parse_amount((string)($splitAmountsPost[$mid] ?? '0')), 2);
                $splitWeights[$mid] = 1.0;
            }
            $splitTotal = array_sum($splitAmounts);
            if (abs($splitTotal - $amount) > 0.01) {
                $errors[] = __('expense.add.split_sum_mismatch', [
                    'sum'   => format_currency($splitTotal, $currency),
                    'total' => format_currency($amount,     $currency),
                ]);
            }
        } elseif ($splitMode === 'weighted') {
            foreach ($selectedMemberIds as $mid) {
                $splitWeights[$mid] = $postedWeights[$mid] ?? 1.0;
            }
            $splitAmounts = calculate_weighted_splits($splitWeights, $amount);
        } else {
            $splitAmounts = calculate_equal_splits($selectedMemberIds, $amount);
            foreach ($selectedMemberIds as $mid) {
                $splitWeights[$mid] = 1.0;
            }
        }
    }

    // Receipt validation
    $hasUpload = isset($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE;
    $receiptTmpPath = null;
    $receiptMime    = '';
    if ($hasUpload) {
        $uploadErr = $_FILES['receipt']['error'];
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $errors[] = __('expense.validation.receipt_too_large', ['max_mb' => $maxReceiptMb]);
        } else {
            $tmpPath   = $_FILES['receipt']['tmp_name'];
            $fileSize  = $_FILES['receipt']['size'];
            $maxBytes  = $maxReceiptMb * 1024 * 1024;

            if ($fileSize > $maxBytes) {
                $errors[] = __('expense.validation.receipt_too_large', ['max_mb' => $maxReceiptMb]);
            } else {
                $mime = mime_content_type($tmpPath) ?: '';
                if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
                    $errors[] = __('expense.validation.receipt_invalid_type');
                } else {
                    $receiptTmpPath = $tmpPath;
                    $receiptMime    = $mime;
                }
            }
        }
    }

    // Exchange rate for foreign currency
    $exchangeRate         = null;
    $amountInGroupCurrency = $amount;
    if (empty($errors) && $currency !== $group['currency'] && $multiCurrency) {
        $manualRate = $manualRateRaw !== '' ? parse_amount($manualRateRaw) : 0.0;
        if ($manualRate > 0) {
            $exchangeRate          = $manualRate;
            $amountInGroupCurrency = round($amount * $exchangeRate, 2);
        } else {
            $fetched = get_exchange_rate($currency, $group['currency']);
            if ($fetched === null) {
                $errors[] = __('expense.exchange_rate_manual');
                $formData['show_rate_field'] = true;
            } else {
                $exchangeRate          = $fetched;
                $amountInGroupCurrency = round($amount * $exchangeRate, 2);
            }
        }
    }

    // ── Save ──────────────────────────────────────────────────────────────────
    if (empty($errors)) {
        $stmtIns = db()->prepare(
            'INSERT INTO expenses
             (group_id, paid_by, description, amount, currency, exchange_rate,
              category_preset, category_custom_id, expense_date, split_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmtIns->execute([
            $group['id'],
            $paidBy,
            $description,
            $amountInGroupCurrency,
            $currency !== $group['currency'] ? $currency : $group['currency'],
            $exchangeRate,
            $categoryPreset ?: null,
            $categoryCustomId ?: null,
            $expenseDate,
            $splitMode,
        ]);
        $expenseId = (int)db()->lastInsertId();

        // Process receipt
        $receiptPath   = null;
        $receiptNumber = null;
        if ($receiptTmpPath !== null) {
            $timestamp = time();
            $ext       = $receiptMime === 'application/pdf' ? 'pdf' : 'jpg';
            $destPath  = BASE_PATH . '/uploads/receipts/' . $group['id'] . '/' . $expenseId . '_' . $timestamp . '.' . $ext;
            if (process_receipt_image($receiptTmpPath, $destPath)) {
                $receiptPath = 'uploads/receipts/' . $group['id'] . '/' . $expenseId . '_' . $timestamp . '.' . $ext;
                // Find payer name
                $payerName = $currentMember['name'];
                foreach ($allMembers as $m) {
                    if ((int)$m['id'] === $paidBy) {
                        $payerName = $m['name'];
                        break;
                    }
                }
                $receiptNumber = generate_receipt_number($group['id'], $paidBy, $payerName, $expenseDate);
                $stmtReceipt = db()->prepare(
                    'UPDATE expenses SET receipt_path = ?, receipt_number = ? WHERE id = ?'
                );
                $stmtReceipt->execute([$receiptPath, $receiptNumber, $expenseId]);
            }
        }

        // Insert splits
        $stmtSplit = db()->prepare(
            'INSERT INTO expense_splits (expense_id, member_id, share_amount, weight) VALUES (?, ?, ?, ?)'
        );
        foreach ($splitAmounts as $mid => $share) {
            $stmtSplit->execute([$expenseId, $mid, $share, $splitWeights[$mid] ?? 1.0]);
        }

        // Activity log
        $payerName = $currentMember['name'];
        foreach ($allMembers as $m) {
            if ((int)$m['id'] === $paidBy) { $payerName = $m['name']; break; }
        }
        log_activity($group['id'], (int)$currentMember['id'], 'expense_added', [
            'description' => $description,
            'amount'      => format_currency($amountInGroupCurrency, $group['currency']),
            'name'        => $payerName,
        ]);

        flash('success', __('expense.add.success'));
        redirect('/group/' . $shareToken);
    }
}

// ─── Render ───────────────────────────────────────────────────────────────────
$pageTitle = __('expense.add.title');
$navLinks  = [
    ['url' => base_url('group/' . $shareToken),             'label' => __('nav.expenses'), 'icon' => 'fa-solid fa-receipt',        'active' => false],
    ['url' => base_url('group/' . $shareToken . '/settle'), 'label' => __('nav.settle'),   'icon' => 'fa-solid fa-scale-balanced', 'active' => false],
];

ob_start();
?>
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap">
    <a href="<?= base_url('group/' . $shareToken) ?>" class="btn btn--ghost btn--sm">← <?= e(__('common.back')) ?></a>
    <h1><?= e(__('expense.add.title')) ?></h1>
</div>

<?php if (!empty($errors)): ?>
<div class="flash flash--error" role="alert">
    <ul style="margin:0;padding-left:1.25rem">
        <?php foreach ($errors as $err): ?>
            <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card">
<form method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <!-- Paid by -->
    <div class="form-group">
        <label for="paid_by"><?= e(__('expense.add.paid_by')) ?> *</label>
        <select id="paid_by" name="paid_by">
            <?php foreach ($allMembers as $m): ?>
            <option value="<?= (int)$m['id'] ?>"<?= (int)$m['id'] === (int)$formData['paid_by'] ? ' selected' : '' ?>>
                <?= e($m['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Description -->
    <div class="form-group">
        <label for="description"><?= e(__('expense.add.description')) ?> *</label>
        <input type="text"
               id="description"
               name="description"
               value="<?= e($formData['description']) ?>"
               placeholder="<?= e(__('expense.add.description_placeholder')) ?>"
               maxlength="255"
               data-required
               data-required-msg="<?= e(__('expense.validation.description_required')) ?>"
               required>
    </div>

    <div class="form-row">
        <!-- Amount -->
        <div class="form-group" style="flex:1">
            <label for="amount"><?= e(__('expense.add.amount')) ?> *</label>
            <input type="text"
                   id="amount"
                   name="amount"
                   value="<?= e($formData['amount']) ?>"
                   inputmode="decimal"
                   placeholder="0,00"
                   data-required
                   data-required-msg="<?= e(__('expense.validation.amount_required')) ?>"
                   data-numeric
                   data-numeric-msg="<?= e(__('validation.invalid_number')) ?>"
                   data-min="0.01"
                   data-min-msg="<?= e(__('expense.validation.amount_zero')) ?>"
                   required>
        </div>

        <?php if ($multiCurrency): ?>
        <!-- Currency -->
        <div class="form-group" style="flex:0 0 130px">
            <label for="currency"><?= e(__('expense.add.currency')) ?></label>
            <select id="currency" name="currency">
                <?php foreach ($currencies as $code): ?>
                <option value="<?= e($code) ?>"<?= $formData['currency'] === $code ? ' selected' : '' ?>>
                    <?= e($code) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="currency" value="<?= e($group['currency']) ?>">
        <?php endif; ?>
    </div>

    <?php if ($multiCurrency): ?>
    <!-- Exchange rate (shown when foreign currency selected) -->
    <div id="exchange-rate-section" class="form-group<?= $formData['currency'] === $group['currency'] && !$formData['show_rate_field'] ? ' hidden' : '' ?>">
        <label for="exchange_rate_manual">
            <?= e(__('expense.exchange_rate_label', ['from' => $formData['currency'], 'to' => $group['currency']])) ?>
        </label>
        <input type="text"
               id="exchange_rate_manual"
               name="exchange_rate_manual"
               value="<?= e($formData['exchange_rate_manual']) ?>"
               inputmode="decimal"
               placeholder="<?= $formData['show_rate_field'] ? e(__('expense.exchange_rate_manual')) : '' ?>">
        <small class="form-hint"><?= e(__('expense.exchange_rate_manual')) ?></small>
    </div>
    <?php endif; ?>

    <!-- Date -->
    <div class="form-group">
        <label for="expense_date"><?= e(__('expense.add.date')) ?> *</label>
        <input type="date"
               id="expense_date"
               name="expense_date"
               value="<?= e($formData['expense_date']) ?>"
               max="<?= date('Y-m-d') ?>"
               required>
    </div>

    <?php if ($categoriesEnabled): ?>
    <!-- Category -->
    <div class="form-group">
        <label for="category"><?= e(__('expense.add.category')) ?><?= $categoriesRequired ? ' *' : '' ?></label>
        <select id="category" name="<?= $showPresets && !empty($customCategories) ? 'category_combined' : ($customCategories ? 'category_custom_id' : 'category_preset') ?>"
                <?php if ($categoriesRequired): ?>data-required data-required-msg="<?= e(__('expense.validation.category_required')) ?>"<?php endif; ?>
                onchange="handleCategoryChange(this)">
            <option value=""><?= e(__('expense.add.category_choose')) ?></option>
            <?php if ($showPresets): ?>
            <optgroup label="— <?= e(__('expense.add.category')) ?> —">
                <?php foreach ($presetCategories as $key): ?>
                <option value="preset:<?= e($key) ?>"
                        <?= $formData['category_preset'] === $key ? 'selected' : '' ?>>
                    <?= e(__('expense.categories.' . $key)) ?>
                </option>
                <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if (!empty($customCategories)): ?>
            <optgroup label="— <?= e(__('group.settings.custom_categories')) ?> —">
                <?php foreach ($customCategories as $cat): ?>
                <option value="custom:<?= (int)$cat['id'] ?>"
                        <?= (int)$formData['category_custom_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['name']) ?>
                </option>
                <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
        </select>
        <input type="hidden" id="category_preset"    name="category_preset"    value="<?= e($formData['category_preset']) ?>">
        <input type="hidden" id="category_custom_id" name="category_custom_id" value="<?= e((string)($formData['category_custom_id'] ?: '')) ?>">
    </div>
    <?php endif; ?>

    <!-- Receipt -->
    <div class="form-group">
        <label for="receipt"><?= e(__('expense.add.receipt')) ?></label>
        <input type="file"
               id="receipt"
               name="receipt"
               accept="image/jpeg,image/png,image/webp,application/pdf"
               capture="environment">
        <small class="form-hint"><?= e(__('expense.add.receipt_hint', ['max_mb' => $maxReceiptMb])) ?></small>
    </div>

    <!-- Split -->
    <fieldset style="border:none;padding:0;margin:0">
        <legend style="font-weight:600;margin-bottom:.75rem"><?= e(__('expense.add.split_title')) ?></legend>

        <!-- Split-Modus -->
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem">
            <?php foreach (['equal' => __('expense.add.split_equal'), 'weighted' => __('expense.add.split_mode_weighted'), 'custom' => __('expense.add.split_custom')] as $modeVal => $modeLabel): ?>
            <label class="split-mode-btn<?= $formData['split_mode'] === $modeVal ? ' split-mode-btn--active' : '' ?>">
                <input type="radio" name="split_mode" value="<?= e($modeVal) ?>"
                       <?= $formData['split_mode'] === $modeVal ? 'checked' : '' ?>
                       style="position:absolute;opacity:0;width:0;height:0">
                <span><?= e($modeLabel) ?></span>
            </label>
            <?php endforeach; ?>
        </div>

        <!-- Mitglieder-Auswahl -->
        <div id="member-checks">
            <?php foreach ($allMembers as $m): ?>
            <label class="check-row">
                <input type="checkbox"
                       name="split_members[]"
                       value="<?= (int)$m['id'] ?>"
                       class="split-member-cb"
                       data-member-id="<?= (int)$m['id'] ?>"
                       <?= in_array((int)$m['id'], array_map('intval', $formData['split_members']), true) ? 'checked' : '' ?>>
                <span><?= e($m['name']) ?></span>
            </label>
            <?php endforeach; ?>
        </div>

        <!-- Anteilig-Sektion -->
        <div id="weighted-split-section"<?= $formData['split_mode'] === 'weighted' ? '' : ' class="hidden"' ?> style="margin-top:.75rem">
            <p class="form-hint"><?= e(__('expense.add.split_weighted_hint')) ?></p>
            <div data-weight-container>
                <?php foreach ($allMembers as $m): ?>
                <?php $mId = (int)$m['id']; $mWeight = $formData['split_weights'][$mId] ?? 1.0; ?>
                <div class="split-row" id="weight-row-<?= $mId ?>">
                    <label for="sw-<?= $mId ?>" class="split-row__label"><?= e($m['name']) ?></label>
                    <input type="text"
                           id="sw-<?= $mId ?>"
                           name="split_weights[<?= $mId ?>]"
                           value="<?= e(rtrim(rtrim(number_format($mWeight, 2, '.', ''), '0'), '.') ?: '1') ?>"
                           inputmode="decimal"
                           placeholder="1"
                           data-weight-input
                           data-member-id="<?= $mId ?>">
                    <span class="split-row__preview" data-weight-preview-<?= $mId ?> style="min-width:5rem;text-align:right;color:var(--color-muted,#6b7280);font-size:.875rem"></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Individuell-Sektion -->
        <div id="custom-split-section"<?= $formData['split_mode'] === 'custom' ? '' : ' class="hidden"' ?> style="margin-top:.75rem">
            <p class="form-hint"><?= e(__('expense.add.split_sum_hint', ['total' => '…'])) ?></p>
            <div data-split-container>
                <?php foreach ($allMembers as $m): ?>
                <div class="split-row" id="split-row-<?= (int)$m['id'] ?>">
                    <label for="sa-<?= (int)$m['id'] ?>" class="split-row__label"><?= e($m['name']) ?></label>
                    <input type="text"
                           id="sa-<?= (int)$m['id'] ?>"
                           name="split_amounts[<?= (int)$m['id'] ?>]"
                           value="<?= e((string)($formData['split_amounts'][$m['id']] ?? '')) ?>"
                           inputmode="decimal"
                           placeholder="0.00"
                           data-split-amount
                           data-member-id="<?= (int)$m['id'] ?>">
                </div>
                <?php endforeach; ?>
                <div class="split-sum">
                    <span><?= e(__('group.view.total')) ?>:</span>
                    <span data-split-sum></span>
                    <span data-split-total="<?= e($formData['amount'] ?: '0') ?>"></span>
                </div>
            </div>
        </div>
    </fieldset>

    <button type="submit" class="btn btn--primary btn--full" style="margin-top:1.5rem">
        <?= e(__('expense.add.submit')) ?>
    </button>
</form>
</div>

<script>
(function () {
    var groupCurrency = <?= json_encode($group['currency']) ?>;

    // ── Exchange rate section visibility ──
    var currencySelect = document.getElementById('currency');
    var rateSection    = document.getElementById('exchange-rate-section');
    if (currencySelect && rateSection) {
        currencySelect.addEventListener('change', function () {
            rateSection.classList.toggle('hidden', this.value === groupCurrency);
            var lbl = rateSection.querySelector('label');
            if (lbl) lbl.textContent = '1 ' + this.value + ' = … ' + groupCurrency;
        });
    }

    // ── Category combined select → hidden inputs ──
    function handleCategoryChange(sel) {
        var val = sel.value;
        var p   = document.getElementById('category_preset');
        var c   = document.getElementById('category_custom_id');
        if (!p || !c) return;
        if (val.startsWith('preset:')) { p.value = val.slice(7); c.value = ''; }
        else if (val.startsWith('custom:')) { p.value = ''; c.value = val.slice(7); }
        else { p.value = ''; c.value = ''; }
    }
    window.handleCategoryChange = handleCategoryChange;

    // ── Parse amount (comma or dot decimal) ──
    function parseAmt(s) {
        s = String(s).trim().replace(/\s/g, '');
        if (/\d+\.\d{3},\d{1,2}$/.test(s)) s = s.replace(/\./g, '').replace(',', '.');
        else if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
        return parseFloat(s) || 0;
    }

    // ── Split mode switching ──
    var weightedSection = document.getElementById('weighted-split-section');
    var customSection   = document.getElementById('custom-split-section');

    function applyMode(mode) {
        if (weightedSection) weightedSection.classList.toggle('hidden', mode !== 'weighted');
        if (customSection)   customSection.classList.toggle('hidden',   mode !== 'custom');
        // update button styling
        document.querySelectorAll('.split-mode-btn').forEach(function(btn) {
            var radio = btn.querySelector('input[type=radio]');
            btn.classList.toggle('split-mode-btn--active', radio && radio.value === mode);
        });
        if (mode === 'weighted') recalcWeights();
    }

    document.querySelectorAll('input[name="split_mode"]').forEach(function(radio) {
        radio.addEventListener('change', function() { applyMode(this.value); });
    });

    // ── Weighted split: live preview ──
    function recalcWeights() {
        var total = parseAmt(document.getElementById('amount')?.value || '0');
        var memberWeights = {};
        document.querySelectorAll('[data-weight-input]').forEach(function(inp) {
            var mid = inp.dataset.memberId;
            var cb  = document.querySelector('.split-member-cb[data-member-id="' + mid + '"]');
            if (cb && cb.checked) {
                memberWeights[mid] = Math.max(0.01, parseFloat(inp.value) || 1);
            }
        });
        var totalW = Object.values(memberWeights).reduce(function(a, b) { return a + b; }, 0);
        Object.entries(memberWeights).forEach(function(entry) {
            var mid = entry[0], w = entry[1];
            var share = totalW > 0 ? (w / totalW) * total : 0;
            var preview = document.querySelector('[data-weight-preview-' + mid + ']');
            if (preview) preview.textContent = '≈ ' + share.toFixed(2).replace('.', ',') + ' ' + groupCurrency;
        });
    }

    document.querySelectorAll('[data-weight-input]').forEach(function(inp) {
        inp.addEventListener('input', recalcWeights);
    });

    // ── Sync split rows (weight + custom) with member checkboxes ──
    document.querySelectorAll('.split-member-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var mid = this.dataset.memberId;
            ['weight-row-', 'split-row-'].forEach(function(prefix) {
                var row = document.getElementById(prefix + mid);
                if (row) {
                    row.style.display = cb.checked ? '' : 'none';
                    if (!cb.checked) {
                        var inp = row.querySelector('input[data-split-amount]');
                        if (inp) inp.value = '';
                    }
                }
            });
            recalcWeights();
        });
        // Initialize hidden state on load
        if (!cb.checked) {
            ['weight-row-', 'split-row-'].forEach(function(prefix) {
                var row = document.getElementById(prefix + cb.dataset.memberId);
                if (row) row.style.display = 'none';
            });
        }
    });

    // ── Update split-total data attribute when amount changes ──
    var amountInput = document.getElementById('amount');
    if (amountInput) {
        amountInput.addEventListener('input', function () {
            var totalEl = document.querySelector('[data-split-total]');
            if (totalEl) totalEl.setAttribute('data-split-total', this.value.replace(',', '.'));
            recalcWeights();
        });
    }

    // ── Init ──
    var activeMode = (document.querySelector('input[name="split_mode"]:checked') || {}).value || 'equal';
    applyMode(activeMode);
    recalcWeights();
})();
</script>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
