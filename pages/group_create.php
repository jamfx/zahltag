<?php
declare(strict_types=1);

$errors          = [];
$defaultCurrency = setting('default_currency', 'EUR');
$multiCurrency   = (bool)(int)setting('multi_currency_enabled', '0');
$formData        = ['name' => '', 'currency' => $defaultCurrency];
$ip              = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$currencies = [
    'EUR' => __('common.currency_eur'),
    'USD' => __('common.currency_usd'),
    'GBP' => __('common.currency_gbp'),
    'CHF' => __('common.currency_chf'),
    'JPY' => __('common.currency_jpy'),
    'CNY' => __('common.currency_cny'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (group_create_rate_limited($ip)) {
        $errors[] = __('group.create.rate_limit');
    } else {
        $name     = trim($_POST['name'] ?? '');
        $currency = strtoupper(trim($_POST['currency'] ?? $defaultCurrency));

        if (!array_key_exists($currency, $currencies)) {
            $currency = $defaultCurrency;
        }

        $formData = ['name' => $name, 'currency' => $currency];

        if ($name === '') {
            $errors[] = __('validation.required');
        } elseif (mb_strlen($name) > 100) {
            $errors[] = __('validation.too_long', ['max' => 100]);
        }

        if (empty($errors)) {
            $adminToken = generate_token(32);
            $shareToken = generate_token(32);

            try {
                $stmt = db()->prepare(
                    'INSERT INTO groups (name, currency, admin_token, share_token) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$name, $currency, $adminToken, $shareToken]);
            } catch (Throwable) {
                $errors[] = __('common.error');
            }

            if (empty($errors)) {
                group_create_record($ip);
                flash('success', __('group.create.success'));
                redirect('/manage/' . $adminToken);
            }
        }
    }
}

$pageTitle = __('group.create.title');
ob_start();
?>
<div class="card" style="max-width:520px;margin:2rem auto">
    <h1 class="card__title"><?= e(__('group.create.title')) ?></h1>

    <?php if (!empty($errors)): ?>
    <div class="flash flash--error" role="alert">
        <ul style="margin:0;padding-left:1.25rem">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="form-group">
            <label for="name"><?= e(__('group.create.name_label')) ?> <span aria-hidden="true" class="required-marker">*</span></label>
            <input type="text"
                   id="name"
                   name="name"
                   value="<?= e($formData['name']) ?>"
                   placeholder="<?= e(__('group.create.name_placeholder')) ?>"
                   maxlength="100"
                   data-required
                   data-required-msg="<?= e(__('validation.required')) ?>"
                   autocomplete="off"
                   required
                   autofocus>
        </div>

        <?php if ($multiCurrency): ?>
        <div class="form-group">
            <label for="currency"><?= e(__('group.create.currency_label')) ?></label>
            <select id="currency" name="currency">
                <?php foreach ($currencies as $code => $label): ?>
                    <option value="<?= e($code) ?>"<?= $formData['currency'] === $code ? ' selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
            <input type="hidden" name="currency" value="<?= e($defaultCurrency) ?>">
        <?php endif; ?>

        <button type="submit" class="btn btn--primary btn--full">
            <?= e(__('group.create.submit')) ?>
        </button>
    </form>
</div>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
