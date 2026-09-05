<?php
declare(strict_types=1);

$adminToken = $params['admin_token'] ?? '';
$group      = require_group_admin($adminToken);
$groupId    = (int)$group['id'];

// ─── Load all data ─────────────────────────────────────────────────────────────

// Expenses
$expenses = [];
try {
    $stmt = db()->prepare(
        'SELECT e.*, m.name AS paid_by_name
         FROM expenses e
         JOIN members m ON m.id = e.paid_by
         WHERE e.group_id = ?
         ORDER BY e.expense_date ASC, e.created_at ASC'
    );
    $stmt->execute([$groupId]);
    $expenses = $stmt->fetchAll();
} catch (Throwable) {}

// Splits
$allSplits = [];
$expenseIds = array_column($expenses, 'id');
if (!empty($expenseIds)) {
    try {
        $in   = implode(',', array_fill(0, count($expenseIds), '?'));
        $stmt = db()->prepare(
            "SELECT es.expense_id, es.member_id, es.share_amount, m.name AS member_name
             FROM expense_splits es
             JOIN members m ON m.id = es.member_id
             WHERE es.expense_id IN ($in)"
        );
        $stmt->execute($expenseIds);
        foreach ($stmt->fetchAll() as $row) {
            $allSplits[(int)$row['expense_id']][] = $row;
        }
    } catch (Throwable) {}
}

// Custom category names
$customCatNames = [];
try {
    $stmt = db()->prepare('SELECT id, name FROM custom_categories WHERE group_id = ?');
    $stmt->execute([$groupId]);
    foreach ($stmt->fetchAll() as $row) {
        $customCatNames[(int)$row['id']] = $row['name'];
    }
} catch (Throwable) {}

// Balances and settlements
$balances    = calculate_balances($groupId);
$settlements = calculate_settlements($balances);

// Payments (for status display)
$payments = [];
try {
    $stmt = db()->prepare(
        'SELECT p.*, fm.name AS from_name, tm.name AS to_name
         FROM payments p
         JOIN members fm ON fm.id = p.from_member_id
         JOIN members tm ON tm.id = p.to_member_id
         WHERE p.group_id = ?'
    );
    $stmt->execute([$groupId]);
    foreach ($stmt->fetchAll() as $p) {
        $key = $p['from_member_id'] . '_' . $p['to_member_id'];
        $payments[$key][] = $p;
    }
} catch (Throwable) {}

// Member payment data (for IBAN/PayPal in settlements)
$memberPaymentData = [];
try {
    $stmt = db()->prepare(
        'SELECT id, payment_iban, payment_iban_name, payment_paypal, payment_wero
         FROM members WHERE group_id = ?'
    );
    $stmt->execute([$groupId]);
    foreach ($stmt->fetchAll() as $m) {
        $memberPaymentData[(int)$m['id']] = $m;
    }
} catch (Throwable) {}

// Period: min/max expense_date
$periodFrom = '';
$periodTo   = '';
if (!empty($expenses)) {
    $dates      = array_column($expenses, 'expense_date');
    $periodFrom = format_date(min($dates));
    $periodTo   = format_date(max($dates));
}

// Site logo
$logoHtml  = '';
$logoPath  = setting('site_logo', '');
if ($logoPath && file_exists(BASE_PATH . '/' . ltrim($logoPath, '/'))) {
    $logoData = base64_encode(file_get_contents(BASE_PATH . '/' . ltrim($logoPath, '/')));
    $logoMime = mime_content_type(BASE_PATH . '/' . ltrim($logoPath, '/')) ?: 'image/png';
    $logoHtml = '<img src="data:' . $logoMime . ';base64,' . $logoData . '" style="max-height:60px;max-width:200px">';
}

// PDF margins — group overrides site default, fallback to site_settings
$resolveMgn = static function (string $col, string $key, string $def) use ($group): float {
    $v = isset($group[$col]) && $group[$col] !== null ? (float)$group[$col] : (float)setting($key, $def);
    return max(0.0, min(5.0, $v));
};
$pdfMarginTop    = $resolveMgn('pdf_margin_top',    'pdf_margin_top',    '1.0');
$pdfMarginRight  = $resolveMgn('pdf_margin_right',  'pdf_margin_right',  '1.0');
$pdfMarginBottom = $resolveMgn('pdf_margin_bottom', 'pdf_margin_bottom', '1.0');
$pdfMarginLeft   = $resolveMgn('pdf_margin_left',   'pdf_margin_left',   '2.5');

// Cover image
$coverImgHtml = '';
if (!empty($group['cover_image'])) {
    $cp = BASE_PATH . '/' . ltrim($group['cover_image'], '/');
    if (file_exists($cp)) {
        $cm = mime_content_type($cp) ?: 'image/jpeg';
        $coverImgHtml = '<img src="data:' . $cm . ';base64,' . base64_encode(file_get_contents($cp)) . '"'
            . ' style="max-width:100%;height:auto;max-height:150pt;display:block;margin:0 auto 20pt">';
    }
}

$isEur      = strtoupper($group['currency']) === 'EUR';
$currency   = $group['currency'];
$siteName   = setting('site_name', 'Zahltag');
$genDate    = format_date(date('Y-m-d'));

// ─── Build GiroCodes for settlements ──────────────────────────────────────────
$giroCodes = [];
if ($isEur) {
    foreach ($settlements as $s) {
        $toData = $memberPaymentData[$s['to_id']] ?? null;
        if ($toData && !empty($toData['payment_iban'])) {
            $ref = __('girocode.reference', ['group' => $group['name']]);
            $qr  = generate_girocode_base64(
                $toData['payment_iban'],
                $toData['payment_iban_name'] ?: $s['to_name'],
                $s['amount'],
                $ref
            );
            if ($qr !== '') {
                $giroCodes[$s['from_id'] . '_' . $s['to_id']] = $qr;
            }
        }
    }
}

// ─── Split receipts: images are embedded directly, PDFs are merged in after
//     rendering (Dompdf can only draw raster images, not other PDF documents) ──
$receiptImages = [];
$receiptPdfs   = [];
foreach ($expenses as $exp) {
    if (empty($exp['receipt_path'])) continue;
    $fullPath = BASE_PATH . '/' . ltrim($exp['receipt_path'], '/');
    if (!file_exists($fullPath)) continue;
    $mime = mime_content_type($fullPath) ?: 'application/octet-stream';
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $receiptImages[(int)$exp['id']] = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
    } elseif ($mime === 'application/pdf') {
        $receiptPdfs[(int)$exp['id']] = $fullPath;
    }
}

// ─── Build HTML document ──────────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.4; margin: <?= $pdfMarginTop ?>cm <?= $pdfMarginRight ?>cm <?= $pdfMarginBottom ?>cm <?= $pdfMarginLeft ?>cm; }
h1 { font-size: 20pt; margin-bottom: 4pt; }
h2 { font-size: 13pt; margin: 16pt 0 8pt; border-bottom: 1px solid #ccc; padding-bottom: 4pt; }
h3 { font-size: 11pt; margin: 12pt 0 4pt; }
p  { margin-bottom: 6pt; }
.text-muted { color: #666; }
.text-right { text-align: right; }
.text-center { text-align: center; }

/* Cover */
.cover { text-align: center; padding: 30pt 0 40pt; }
.cover-meta { margin-top: 20pt; font-size: 10pt; color: #444; }
.cover-meta td { padding: 3pt 10pt; text-align: left; }
.cover-meta td:first-child { font-weight: bold; color: #1a1a1a; text-align: right; padding-right: 8pt; }

/* Tables */
table { width: 100%; border-collapse: collapse; margin-bottom: 12pt; font-size: 9pt; }
thead th { background: #f0f0f0; font-weight: bold; padding: 5pt 6pt; text-align: left; border: 1px solid #ccc; }
tbody td { padding: 4pt 6pt; border: 1px solid #e0e0e0; vertical-align: top; }
tbody tr:nth-child(even) td { background: #fafafa; }
tfoot td { font-weight: bold; background: #f0f0f0; padding: 5pt 6pt; border: 1px solid #ccc; }

/* Positive/negative */
.pos { color: #16a34a; }
.neg { color: #dc2626; }

/* Settlement cards */
.settlement { margin-bottom: 10pt; padding: 8pt; border: 1px solid #e0e0e0; border-radius: 4pt; }
.settlement-header { font-weight: bold; font-size: 11pt; margin-bottom: 6pt; }
.settlement-info { font-size: 9pt; color: #444; }
.settlement-giro { margin-top: 8pt; }
.status-badge { display: inline-block; padding: 2pt 6pt; border-radius: 3pt; font-size: 8pt; font-weight: bold; }
.status-open { background: #fef3c7; color: #92400e; }
.status-unconfirmed { background: #dbeafe; color: #1e40af; }
.status-confirmed { background: #d1fae5; color: #065f46; }

/* Receipt page */
.receipt-page { page-break-before: always; padding: 10pt 0; }
.receipt-page img { max-width: 100%; max-height: 680pt; display: block; margin: 10pt auto; }

/* Page break */
.page-break { page-break-after: always; }
</style>
</head>
<body>

<!-- ── Cover page ──────────────────────────────────────────────────────────── -->
<div class="cover">
    <?= $coverImgHtml ?>
    <?= $logoHtml ?>
    <h1 style="margin-top:<?= $logoHtml ? '8pt' : '16pt' ?>"><?= htmlspecialchars(__('export.pdf_title'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p style="font-size:14pt;margin:4pt 0"><?= htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8') ?></p>
    <table class="cover-meta" style="width:auto;margin:20pt auto;border:none">
        <tr>
            <td><?= htmlspecialchars(__('export.pdf_generated'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($genDate, ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php if ($periodFrom && $periodTo): ?>
        <tr>
            <td><?= htmlspecialchars(__('export.pdf_period'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($periodFrom . ' – ' . $periodTo, ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td><?= htmlspecialchars(__('export.pdf_members'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= count($balances) ?></td>
        </tr>
        <?php if (!empty($expenses)): ?>
        <tr>
            <td><?= htmlspecialchars(__('export.pdf_total'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars(format_currency(array_sum(array_column($expenses, 'amount')), $currency), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php endif; ?>
    </table>
</div>

<?php if (!empty($expenses)): ?>
<div class="page-break"></div>

<!-- ── Expense table ───────────────────────────────────────────────────────── -->
<h2><?= htmlspecialchars(__('export.pdf_expenses_title'), ENT_QUOTES, 'UTF-8') ?></h2>

<table>
    <thead>
        <tr>
            <th style="width:5%"><?= htmlspecialchars(__('export.pdf_col_nr'), ENT_QUOTES, 'UTF-8') ?></th>
            <th style="width:10%"><?= htmlspecialchars(__('export.pdf_col_date'), ENT_QUOTES, 'UTF-8') ?></th>
            <th style="width:<?= $group['categories_enabled'] ? '30%' : '40%' ?>"><?= htmlspecialchars(__('export.pdf_col_description'), ENT_QUOTES, 'UTF-8') ?></th>
            <th style="width:15%"><?= htmlspecialchars(__('export.pdf_col_paid_by'), ENT_QUOTES, 'UTF-8') ?></th>
            <th class="text-right" style="width:12%"><?= htmlspecialchars(__('export.pdf_col_amount'), ENT_QUOTES, 'UTF-8') ?></th>
            <?php if ($group['categories_enabled']): ?>
            <th style="width:15%"><?= htmlspecialchars(__('export.pdf_col_category'), ENT_QUOTES, 'UTF-8') ?></th>
            <?php endif; ?>
            <th style="width:13%"><?= htmlspecialchars(__('export.pdf_col_receipt_nr'), ENT_QUOTES, 'UTF-8') ?></th>
        </tr>
    </thead>
    <tbody>
    <?php
    $grandTotal = 0.0;
    foreach ($expenses as $i => $exp):
        $grandTotal += (float)$exp['amount'];
        $cat = '';
        if ($exp['category_preset']) {
            $cat = __('expense.categories.' . $exp['category_preset']);
        } elseif ($exp['category_custom_id'] && isset($customCatNames[(int)$exp['category_custom_id']])) {
            $cat = $customCatNames[(int)$exp['category_custom_id']];
        }
        $amountStr = format_currency((float)$exp['amount'], $exp['currency']);
        if ($exp['currency'] !== $currency && $exp['exchange_rate']) {
            $converted  = round((float)$exp['amount'] * (float)$exp['exchange_rate'], 2);
            $amountStr .= ' ≈ ' . format_currency($converted, $currency);
        }
    ?>
    <tr>
        <td class="text-center"><?= $i + 1 ?></td>
        <td><?= htmlspecialchars(format_date($exp['expense_date']), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($exp['description'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($exp['paid_by_name'], ENT_QUOTES, 'UTF-8') ?></td>
        <td class="text-right"><?= htmlspecialchars($amountStr, ENT_QUOTES, 'UTF-8') ?></td>
        <?php if ($group['categories_enabled']): ?>
        <td><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></td>
        <?php endif; ?>
        <td style="font-size:8pt"><?= htmlspecialchars($exp['receipt_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="<?= $group['categories_enabled'] ? '4' : '3' ?>" class="text-right">
                <?= htmlspecialchars(__('export.pdf_col_total'), ENT_QUOTES, 'UTF-8') ?>
            </td>
            <td class="text-right"><?= htmlspecialchars(format_currency($grandTotal, $currency), ENT_QUOTES, 'UTF-8') ?></td>
            <?php if ($group['categories_enabled']): ?><td></td><?php endif; ?>
            <td></td>
        </tr>
    </tfoot>
</table>

<!-- ── Balance breakdown ───────────────────────────────────────────────────── -->
<h2><?= htmlspecialchars(__('export.pdf_balances_title'), ENT_QUOTES, 'UTF-8') ?></h2>

<table>
    <thead>
        <tr>
            <th style="width:30%"><?= htmlspecialchars(__('member.name'), ENT_QUOTES, 'UTF-8') ?></th>
            <th class="text-right" style="width:22%"><?= htmlspecialchars(__('settlement.paid_total'), ENT_QUOTES, 'UTF-8') ?></th>
            <th class="text-right" style="width:22%"><?= htmlspecialchars(__('settlement.owes_total'), ENT_QUOTES, 'UTF-8') ?></th>
            <th class="text-right" style="width:26%"><?= htmlspecialchars(__('settlement.balance'), ENT_QUOTES, 'UTF-8') ?></th>
        </tr>
    </thead>
    <tbody>
    <?php
    $sorted = $balances;
    usort($sorted, fn($a, $b) => $b['balance'] <=> $a['balance']);
    foreach ($sorted as $b):
        $balClass = $b['balance'] > 0.005 ? 'pos' : ($b['balance'] < -0.005 ? 'neg' : '');
    ?>
    <tr>
        <td><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></td>
        <td class="text-right"><?= htmlspecialchars(format_currency($b['paid'], $currency), ENT_QUOTES, 'UTF-8') ?></td>
        <td class="text-right"><?= htmlspecialchars(format_currency($b['owes'], $currency), ENT_QUOTES, 'UTF-8') ?></td>
        <td class="text-right <?= $balClass ?>"><?= htmlspecialchars(format_currency($b['balance'], $currency), ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if (!empty($settlements)): ?>
<!-- ── Suggested payments ──────────────────────────────────────────────────── -->
<h2><?= htmlspecialchars(__('export.pdf_settlements_title'), ENT_QUOTES, 'UTF-8') ?></h2>

<?php foreach ($settlements as $s):
    $key      = $s['from_id'] . '_' . $s['to_id'];
    $pmtList  = $payments[$key] ?? [];
    $confirmed   = array_filter($pmtList, fn($p) => (int)$p['confirmed_by_recipient'] === 1);
    $unconfirmed = array_filter($pmtList, fn($p) => (int)$p['confirmed_by_recipient'] === 0);
    if (!empty($confirmed)) {
        $statusClass = 'status-confirmed';
        $statusText  = __('settlement.status_confirmed');
    } elseif (!empty($unconfirmed)) {
        $statusClass = 'status-unconfirmed';
        $statusText  = __('settlement.status_unconfirmed');
    } else {
        $statusClass = 'status-open';
        $statusText  = __('settlement.status_pending');
    }
    $toData = $memberPaymentData[$s['to_id']] ?? null;
    $giroKey = $s['from_id'] . '_' . $s['to_id'];
?>
<div class="settlement">
    <div class="settlement-header">
        <?= htmlspecialchars($s['from_name'], ENT_QUOTES, 'UTF-8') ?>
        → <?= htmlspecialchars($s['to_name'], ENT_QUOTES, 'UTF-8') ?>:
        <?= htmlspecialchars(format_currency($s['amount'], $currency), ENT_QUOTES, 'UTF-8') ?>
        <span class="status-badge <?= $statusClass ?>" style="margin-left:8pt"><?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php if ($toData): ?>
    <div class="settlement-info">
        <?php if (!empty($toData['payment_iban'])): ?>
        <p><?= htmlspecialchars(__('settlement.payment_via_iban'), ENT_QUOTES, 'UTF-8') ?>:
           <?= htmlspecialchars(format_iban($toData['payment_iban']), ENT_QUOTES, 'UTF-8') ?>
           <?php if (!empty($toData['payment_iban_name'])): ?>
             (<?= htmlspecialchars($toData['payment_iban_name'], ENT_QUOTES, 'UTF-8') ?>)
           <?php endif; ?>
        </p>
        <?php endif; ?>
        <?php if (!empty($toData['payment_paypal'])): ?>
        <p><?= htmlspecialchars(__('settlement.payment_via_paypal'), ENT_QUOTES, 'UTF-8') ?>:
           <?= htmlspecialchars($toData['payment_paypal'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($toData['payment_wero'])): ?>
        <p><?= htmlspecialchars(__('settlement.payment_via_wero'), ENT_QUOTES, 'UTF-8') ?>:
           <?= htmlspecialchars($toData['payment_wero'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (isset($giroCodes[$giroKey])): ?>
    <div class="settlement-giro">
        <img src="<?= $giroCodes[$giroKey] ?>" style="width:100pt;height:100pt">
        <br><span class="text-muted" style="font-size:8pt"><?= htmlspecialchars(__('settlement.girocode_title'), ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; // settlements ?>

<?php endif; // expenses ?>

<!-- ── Receipt pages ───────────────────────────────────────────────────────── -->
<?php
$receiptExpenses = array_filter($expenses, fn($e) => !empty($e['receipt_path']) && isset($receiptImages[(int)$e['id']]));
foreach ($receiptExpenses as $exp):
?>
<div class="receipt-page">
    <h2><?= htmlspecialchars(__('export.pdf_receipts_title'), ENT_QUOTES, 'UTF-8') ?></h2>
    <h3><?= htmlspecialchars($exp['receipt_number'] ?? $exp['description'], ENT_QUOTES, 'UTF-8') ?></h3>
    <p class="text-muted" style="font-size:9pt">
        <?= htmlspecialchars($exp['description'], ENT_QUOTES, 'UTF-8') ?> |
        <?= htmlspecialchars(format_currency((float)$exp['amount'], $exp['currency']), ENT_QUOTES, 'UTF-8') ?> |
        <?= htmlspecialchars(format_date($exp['expense_date']), ENT_QUOTES, 'UTF-8') ?> |
        <?= htmlspecialchars($exp['paid_by_name'], ENT_QUOTES, 'UTF-8') ?>
    </p>
    <img src="<?= $receiptImages[(int)$exp['id']] ?>" alt="">
</div>
<?php endforeach; ?>

</body>
</html>
<?php
$html = ob_get_clean();

// ─── Render with Dompdf ────────────────────────────────────────────────────────
$options = new \Dompdf\Options();
$options->setIsRemoteEnabled(false);
$options->setIsHtml5ParserEnabled(true);
$options->setDefaultFont('DejaVu Sans');
$options->setChroot(BASE_PATH);

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Filename
$safeGroupName = preg_replace('/[^\p{L}\p{N}_\-]/u', '_', $group['name']);
$dateStr       = date('Y-m-d');
$filename      = str_replace(
    ['{group}', '{date}'],
    [$safeGroupName, $dateStr],
    __('export.pdf_filename')
);

// No PDF receipts to merge in — stream Dompdf's output directly.
if (empty($receiptPdfs)) {
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

// ─── Merge PDF receipts into the rendered document ────────────────────────────
// Dompdf can only draw raster images, so PDF receipts are appended afterwards
// page-for-page (vector-perfect, multi-page-capable) using FPDI. Each PDF
// receipt gets a short divider page (same header info as the image receipt
// pages) followed by its own pages.
$tmpFiles = [];
$mainTmp  = tempnam(sys_get_temp_dir(), 'zahltag_stmt_');
file_put_contents($mainTmp, $dompdf->output());
$tmpFiles[] = $mainTmp;

$merged = new \setasign\Fpdi\Fpdi();

$mainPageCount = $merged->setSourceFile($mainTmp);
for ($i = 1; $i <= $mainPageCount; $i++) {
    $tplId = $merged->importPage($i);
    $size  = $merged->getTemplateSize($tplId);
    $merged->AddPage($size['orientation'], [$size['width'], $size['height']]);
    $merged->useTemplate($tplId);
}

foreach ($expenses as $exp) {
    $expId = (int)$exp['id'];
    if (!isset($receiptPdfs[$expId])) continue;
    $receiptPath = $receiptPdfs[$expId];

    // Probe the receipt PDF first (e.g. encrypted PDFs can't be parsed by FPDI's
    // free parser) so the divider page can note it if embedding isn't possible.
    $embedFailed     = false;
    $receiptPageCount = 0;
    try {
        $receiptPageCount = $merged->setSourceFile($receiptPath);
    } catch (\Throwable) {
        $embedFailed = true;
    }

    // ── Divider page (same style/info as the image receipt pages) ─────────────
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="UTF-8">
    <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.4; margin: <?= $pdfMarginTop ?>cm <?= $pdfMarginRight ?>cm <?= $pdfMarginBottom ?>cm <?= $pdfMarginLeft ?>cm; }
    h2 { font-size: 13pt; margin: 0 0 8pt; border-bottom: 1px solid #ccc; padding-bottom: 4pt; }
    h3 { font-size: 11pt; margin: 12pt 0 4pt; }
    .text-muted { color: #666; }
    .embed-warning { margin-top: 16pt; padding: 8pt; background: #fef3c7; color: #92400e; font-size: 9pt; border-radius: 4pt; }
    </style>
    </head>
    <body>
    <h2><?= htmlspecialchars(__('export.pdf_receipts_title'), ENT_QUOTES, 'UTF-8') ?></h2>
    <h3><?= htmlspecialchars($exp['receipt_number'] ?? $exp['description'], ENT_QUOTES, 'UTF-8') ?></h3>
    <p class="text-muted" style="font-size:9pt">
        <?= htmlspecialchars($exp['description'], ENT_QUOTES, 'UTF-8') ?> |
        <?= htmlspecialchars(format_currency((float)$exp['amount'], $exp['currency']), ENT_QUOTES, 'UTF-8') ?> |
        <?= htmlspecialchars(format_date($exp['expense_date']), ENT_QUOTES, 'UTF-8') ?> |
        <?= htmlspecialchars($exp['paid_by_name'], ENT_QUOTES, 'UTF-8') ?>
    </p>
    <?php if ($embedFailed): ?>
    <p class="embed-warning"><?= htmlspecialchars(__('export.pdf_receipt_embed_failed'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    </body>
    </html>
    <?php
    $dividerHtml = ob_get_clean();

    $dividerOptions = new \Dompdf\Options();
    $dividerOptions->setIsRemoteEnabled(false);
    $dividerOptions->setIsHtml5ParserEnabled(true);
    $dividerOptions->setDefaultFont('DejaVu Sans');
    $dividerOptions->setChroot(BASE_PATH);
    $dividerPdf = new \Dompdf\Dompdf($dividerOptions);
    $dividerPdf->loadHtml($dividerHtml);
    $dividerPdf->setPaper('A4', 'portrait');
    $dividerPdf->render();

    $dividerTmp = tempnam(sys_get_temp_dir(), 'zahltag_div_');
    file_put_contents($dividerTmp, $dividerPdf->output());
    $tmpFiles[] = $dividerTmp;

    $dividerPageCount = $merged->setSourceFile($dividerTmp);
    for ($i = 1; $i <= $dividerPageCount; $i++) {
        $tplId = $merged->importPage($i);
        $size  = $merged->getTemplateSize($tplId);
        $merged->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $merged->useTemplate($tplId);
    }

    if ($embedFailed || $receiptPageCount < 1) continue;

    // Switching source back to the receipt PDF (setSourceFile above pointed at
    // the divider) — re-parsing here is deliberate, not a leftover duplicate call.
    try {
        $merged->setSourceFile($receiptPath);
        for ($i = 1; $i <= $receiptPageCount; $i++) {
            $tplId = $merged->importPage($i);
            $size  = $merged->getTemplateSize($tplId);
            $merged->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $merged->useTemplate($tplId);
        }
    } catch (\Throwable) {
        // Divider page was already rendered without the warning note in this
        // rare case (probe succeeded, actual import failed) — skip silently.
    }
}

$merged->Output('D', $filename);

foreach ($tmpFiles as $tmpFile) {
    @unlink($tmpFile);
}
exit;
