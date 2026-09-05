<?php
declare(strict_types=1);

$pageTitle = __('app.tagline');

ob_start();
?>

<div class="hero">
    <h1 class="hero__title"><?= e(__('landing.hero_title')) ?></h1>
    <p class="hero__text"><?= e(__('landing.hero_text')) ?></p>

    <div class="hero__actions">
        <a href="<?= base_url('create') ?>" class="btn btn--primary btn--lg">
            <?= e(__('landing.create_group')) ?>
        </a>

        <form method="get" action="" id="join-form" onsubmit="return handleJoinSubmit(event)">
            <div class="copy-field">
                <input type="url"
                       name="join_link"
                       id="join-link"
                       placeholder="<?= e(__('landing.join_placeholder')) ?>"
                       class="btn--lg"
                       autocomplete="off"
                       style="min-width:260px">
                <button type="submit" class="btn btn--secondary btn--lg">
                    <?= e(__('group.join.submit')) ?>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="feature-grid">
    <div class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">👤</span>
        <p class="feature-card__title"><?= e(__('landing.feature_1_title')) ?></p>
        <p class="feature-card__text"><?= e(__('landing.feature_1_text')) ?></p>
    </div>
    <div class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">⚖️</span>
        <p class="feature-card__title"><?= e(__('landing.feature_2_title')) ?></p>
        <p class="feature-card__text"><?= e(__('landing.feature_2_text')) ?></p>
    </div>
    <div class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">📱</span>
        <p class="feature-card__title"><?= e(__('landing.feature_3_title')) ?></p>
        <p class="feature-card__text"><?= e(__('landing.feature_3_text')) ?></p>
    </div>
    <div class="feature-card">
        <span class="feature-card__icon" aria-hidden="true">📄</span>
        <p class="feature-card__title"><?= e(__('landing.feature_4_title')) ?></p>
        <p class="feature-card__text"><?= e(__('landing.feature_4_text')) ?></p>
    </div>
</div>

<script>
function handleJoinSubmit(e) {
    e.preventDefault();
    var val = document.getElementById('join-link').value.trim();
    if (!val) return false;
    // Extract path from URL or use as-is
    try {
        var url = new URL(val);
        window.location.href = url.pathname + url.search;
    } catch (_) {
        // Not a full URL – try as path
        if (val.startsWith('/')) {
            window.location.href = val;
        }
    }
    return false;
}
</script>

<?php
$content = ob_get_clean();
require BASE_PATH . '/templates/layout.php';
