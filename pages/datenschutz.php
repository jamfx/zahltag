<?php
$pageTitle = __('nav.datenschutz');
ob_start();
$text = setting('datenschutz_text', '');
echo $text ? $text : '<p><em>' . e(__('common.info')) . '</em></p>';
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
