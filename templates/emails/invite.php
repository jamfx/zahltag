<?php
/**
 * Invite email template.
 * Expected variables: $groupName (string), $shareUrl (string), $siteName (string)
 */
declare(strict_types=1);

$g = htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8');
$u = htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8');
$s = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_SESSION['lang'] ?? 'de', ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body { font-family: Arial, Helvetica, sans-serif; font-size: 15px; color: #1a1a1a; background: #f5f5f5; margin: 0; padding: 20px; }
.wrapper { max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; }
.header { background: #2563eb; color: #ffffff; padding: 24px 32px; font-size: 20px; font-weight: bold; }
.body { padding: 32px; }
.body p { margin: 0 0 16px; line-height: 1.6; }
.cta { display: inline-block; background: #2563eb; color: #ffffff !important; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: bold; font-size: 16px; margin: 8px 0 16px; }
.footer { padding: 16px 32px; background: #f5f5f5; font-size: 12px; color: #888; }
</style>
</head>
<body>
<div class="wrapper">
    <div class="header"><?= $s ?></div>
    <div class="body">
        <p><?= htmlspecialchars(__('email.invite.greeting'), ENT_QUOTES, 'UTF-8') ?></p>
        <p><?= htmlspecialchars(__('email.invite.text', ['group' => $groupName]), ENT_QUOTES, 'UTF-8') ?></p>
        <a href="<?= $u ?>" class="cta"><?= htmlspecialchars(__('email.invite.cta'), ENT_QUOTES, 'UTF-8') ?></a>
        <p style="font-size:13px;color:#666;word-break:break-all"><?= $u ?></p>
    </div>
    <div class="footer">
        <?= htmlspecialchars(__('email.invite.footer'), ENT_QUOTES, 'UTF-8') ?>
    </div>
</div>
</body>
</html>
<?php
// Also provide a plain-text fallback
$textBody  = __('email.invite.greeting') . "\n\n";
$textBody .= __('email.invite.text', ['group' => $groupName]) . "\n\n";
$textBody .= $shareUrl . "\n\n";
$textBody .= __('email.invite.footer') . "\n";
