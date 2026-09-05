<?php
declare(strict_types=1);

$adminToken = $params['admin_token'] ?? '';
$group      = require_group_admin($adminToken);

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/manage/' . $adminToken . '/settings');
}

verify_csrf();

$rawEmails  = trim($_POST['emails'] ?? '');
$errors     = [];

// Parse comma-separated email list
$emailList = array_filter(
    array_map('trim', explode(',', $rawEmails)),
    fn($e) => $e !== ''
);

if (empty($emailList)) {
    flash('error', __('group.settings.send_invite_error'));
    redirect('/manage/' . $adminToken . '/settings');
}

// Validate email addresses
$validEmails = [];
foreach ($emailList as $email) {
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validEmails[] = $email;
    }
}

if (empty($validEmails)) {
    flash('error', __('group.settings.send_invite_error'));
    redirect('/manage/' . $adminToken . '/settings');
}

// Load mailer
require_once BASE_PATH . '/includes/Mailer.php';
$mailer = Mailer::fromSettings();

$shareUrl  = base_url('group/' . $group['share_token']);
$siteName  = setting('site_name', 'Zahltag');
$groupName = $group['name'];
$subject   = __('email.invite.subject', ['group' => $groupName]);

$failed = 0;
foreach ($validEmails as $email) {
    // Render email template
    ob_start();
    require BASE_PATH . '/templates/emails/invite.php';
    $htmlBody = ob_get_clean();

    if (!$mailer->send($email, $subject, $htmlBody, $textBody ?? '')) {
        $failed++;
        error_log('Zahltag invite mail failed to ' . $email . ': ' . $mailer->getLastError());
    }
    // Email address is NOT stored – discarded immediately after send (DSGVO)
}

if ($failed > 0 && $failed === count($validEmails)) {
    flash('error', __('group.settings.send_invite_error'));
} else {
    flash('success', __('group.settings.send_invite_success'));
}

redirect('/manage/' . $adminToken . '/settings');
