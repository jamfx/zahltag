<?php
declare(strict_types=1);

http_response_code(404);
$pageTitle = __('common.not_found');

ob_start();
?>
<div class="error-page">
    <div class="error-page__code">404</div>
    <h1><?= e(__('common.not_found')) ?></h1>
    <p class="text-muted"><?= e(__('common.not_found_text')) ?></p>
    <p class="mt-2"><a href="<?= e(base_url()) ?>" class="btn btn--primary"><?= e(__('nav.home')) ?></a></p>
</div>
<?php
$content = ob_get_clean();
require dirname(__DIR__) . '/layout.php';
