<?php
declare(strict_types=1);

unset(
    $_SESSION['admin_id'],
    $_SESSION['admin_username'],
    $_SESSION['admin_totp_pending_id']
);

flash('success', __('admin.login.logout_success'));
redirect('/admin');
