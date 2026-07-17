<?php
/**
 * Segmented switcher between the two log views the operator lives in:
 *   /admin/clicks  ·  /admin/pixel
 *
 * Carries forward shared filters so toggling doesn't reset the operator's
 * working set. We're conservative about which keys cross over — both
 * surfaces accept campaign_id, since and search; everything else
 * (country/device/event_name/domain/etc.) is page-specific and dropped.
 *
 * @var string $activePath  Current /admin/* path. e.g. '/admin/clicks'.
 */
$activePath = $activePath ?? '';

$shared = [];
foreach (['campaign_id', 'since', 'search'] as $k) {
    if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') {
        $shared[$k] = $_GET[$k];
    }
}
$qs = $shared !== [] ? '?' . http_build_query($shared) : '';

$tabs = [
    ['/admin/clicks', t('clicks.tab'), 'rec'],
    ['/admin/pixel',  t('pixel.tab'),  'evt'],
];
?>
<nav class="log-switcher" aria-label="<?= e(t('log_switcher.aria')) ?>">
    <?php foreach ($tabs as [$href, $label, $unit]): ?>
        <?php $active = $activePath === $href; ?>
        <a href="<?= e(url($href . $qs)) ?>" class="log-switcher-tab<?= $active ? ' is-active' : '' ?>">
            <span class="log-switcher-label"><?= e($label) ?></span>
            <span class="log-switcher-unit"><?= e($unit) ?></span>
        </a>
    <?php endforeach; ?>
    <?php if ($shared !== []): ?>
        <span class="log-switcher-hint" title="<?= e(t('log_switcher.shared_filters')) ?>">
            <?= e(tn('log_switcher.filters_kept', count($shared))) ?>
        </span>
    <?php endif; ?>
</nav>
