<?php
$pageTitle = __('nav.impressum');
ob_start();
$text = setting('impressum_text', '');
echo $text ? $text : '<p><em>' . e(__('common.info')) . '</em></p>';
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
