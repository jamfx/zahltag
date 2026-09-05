<?php
declare(strict_types=1);

$shareToken    = $params['share_token'] ?? '';
$currentMember = require_member($shareToken);
$group         = get_group_by_share_token($shareToken);

if (!$group) {
    http_response_code(404); exit;
}

$errors   = [];
$success  = false;
$formData = [
    'payment_paypal'     => $currentMember['payment_paypal'] ?? '',
    'payment_wero'       => $currentMember['payment_wero']   ?? '',
    'payment_iban'       => $currentMember['payment_iban']   ? format_iban($currentMember['payment_iban']) : '',
    'payment_iban_name'  => $currentMember['payment_iban_name'] ?? '',
];

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $paypal   = trim($_POST['payment_paypal']    ?? '');
    $wero     = trim($_POST['payment_wero']      ?? '');
    $ibanRaw  = trim($_POST['payment_iban']      ?? '');
    $ibanName = trim($_POST['payment_iban_name'] ?? '');

    $formData = [
        'payment_paypal'    => $paypal,
        'payment_wero'      => $wero,
        'payment_iban'      => $ibanRaw,
        'payment_iban_name' => $ibanName,
    ];

    $ibanClean = strtoupper(preg_replace('/\s+/', '', $ibanRaw));

    if (mb_strlen($paypal) > 255) $errors[] = __('validation.too_long', ['max' => 255]);
    if (mb_strlen($wero)   > 255) $errors[] = __('validation.too_long', ['max' => 255]);
    if (mb_strlen($ibanName) > 100) $errors[] = __('validation.too_long', ['max' => 100]);

    if ($ibanClean !== '' && !validate_iban($ibanClean)) {
        $errors[] = __('payment.iban_invalid');
    }

    if (empty($errors)) {
        $stmt = db()->prepare(
            'UPDATE members SET payment_paypal = ?, payment_wero = ?, payment_iban = ?, payment_iban_name = ?
             WHERE id = ? AND group_id = ?'
        );
        $stmt->execute([
            $paypal   ?: null,
            $wero     ?: null,
            $ibanClean ?: null,
            $ibanName ?: null,
            (int)$currentMember['id'],
            (int)$group['id'],
        ]);
        $success = true;
        flash('success', __('member.payment_saved'));
        redirect('/group/' . $shareToken . '/payment-data');
    }
}

// ─── Render ───────────────────────────────────────────────────────────────────
$pageTitle = __('member.payment_data_title');
$navLinks  = [
    ['url' => base_url('group/' . $shareToken),                  'label' => __('nav.expenses'), 'icon' => 'fa-solid fa-receipt',        'active' => false],
    ['url' => base_url('group/' . $shareToken . '/settle'),       'label' => __('nav.settle'),   'icon' => 'fa-solid fa-scale-balanced', 'active' => false],
    ['url' => base_url('group/' . $shareToken . '/payment-data'), 'label' => __('group.view.my_payment_data'), 'icon' => 'fa-solid fa-wallet', 'active' => true],
];

ob_start();
?>
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap">
    <a href="<?= base_url('group/' . $shareToken . '/settle') ?>" class="btn btn--ghost btn--sm">← <?= e(__('nav.settle')) ?></a>
    <h1><?= e(__('member.payment_data_title')) ?></h1>
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
    <p class="text-muted" style="margin-bottom:1.5rem">
        <?= e(__('group.view.signed_in_as', ['name' => $currentMember['name']])) ?>
    </p>

    <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="form-group">
            <label for="payment_paypal"><i class="fa-brands fa-paypal" aria-hidden="true"></i> <?= e(__('member.payment_paypal')) ?></label>
            <input type="text" id="payment_paypal" name="payment_paypal"
                   value="<?= e($formData['payment_paypal']) ?>"
                   placeholder="name@example.com or paypal.me/…"
                   maxlength="255" inputmode="email">
        </div>

        <div class="form-group">
            <label for="payment_wero"><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i> <?= e(__('member.payment_wero')) ?></label>
            <input type="text" id="payment_wero" name="payment_wero"
                   value="<?= e($formData['payment_wero']) ?>"
                   placeholder="+49 …"
                   maxlength="255" inputmode="tel">
        </div>

        <hr style="border:none;border-top:1px solid var(--color-border);margin:1.5rem 0">

        <div class="form-group">
            <label for="payment_iban"><i class="fa-solid fa-building-columns" aria-hidden="true"></i> <?= e(__('member.payment_iban')) ?></label>
            <input type="text" id="payment_iban" name="payment_iban"
                   value="<?= e($formData['payment_iban']) ?>"
                   placeholder="DE89 3704 0044 0532 0130 00"
                   maxlength="42"
                   autocomplete="off"
                   oninput="this.value = this.value.toUpperCase()">
            <small class="form-hint" id="iban-feedback"></small>
        </div>

        <div class="form-group">
            <label for="payment_iban_name"><?= e(__('member.payment_iban_name')) ?></label>
            <input type="text" id="payment_iban_name" name="payment_iban_name"
                   value="<?= e($formData['payment_iban_name']) ?>"
                   placeholder="<?= e($currentMember['name']) ?>"
                   maxlength="100" autocomplete="name">
            <small class="form-hint"><?= e(__('payment.iban_name')) ?></small>
        </div>

        <button type="submit" class="btn btn--primary"><?= e(__('member.payment_save')) ?></button>
    </form>
</div>

<script>
(function () {
    var ibanInput = document.getElementById('payment_iban');
    if (!ibanInput) return;
    ibanInput.addEventListener('blur', function () {
        var raw = this.value.replace(/\s+/g, '').toUpperCase();
        if (!raw) return;
        // Format with spaces every 4 chars for readability
        this.value = raw.match(/.{1,4}/g).join(' ');
    });
})();
</script>
<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
