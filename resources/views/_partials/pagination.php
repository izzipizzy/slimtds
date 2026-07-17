<?php
/**
 * Pagination with prev/next + ellipsis (max 7 visible numbers).
 *
 * @var int    $page
 * @var int    $pages
 * @var string $baseUrl    e.g. '/admin/campaigns'
 * @var array  $extraQuery extra query params to preserve
 */
$page  = max(1, (int)($page ?? 1));
$pages = max(1, (int)($pages ?? 1));
$baseUrl = $baseUrl ?? '/';
$extraQuery = $extraQuery ?? [];
if ($pages <= 1) return;

$buildUrl = function (int $p) use ($baseUrl, $extraQuery): string {
    $q = array_merge($extraQuery, ['page' => $p]);
    return $baseUrl . '?' . http_build_query(array_filter($q, fn ($v) => $v !== '' && $v !== null));
};

$visible = [];
if ($pages <= 7) {
    $visible = range(1, $pages);
} else {
    $visible[] = 1;
    if ($page > 4) $visible[] = '…';
    $start = max(2, $page - 1);
    $end   = min($pages - 1, $page + 1);
    for ($i = $start; $i <= $end; $i++) $visible[] = $i;
    if ($page < $pages - 3) $visible[] = '…';
    $visible[] = $pages;
}
?>
<nav style="display:flex;gap:4px;margin-top:20px;align-items:center;justify-content:center;font-family:var(--font-sans);font-size:0.825rem" aria-label="<?= e(t('pagination.aria')) ?>">
    <?php if ($page > 1): ?>
        <a href="<?= e(url($buildUrl($page - 1))) ?>" class="btn-ghost" style="padding:4px 10px;font-size:0.8rem" aria-label="<?= e(t('pagination.prev_page')) ?>">‹ <?= e(t('pagination.prev')) ?></a>
    <?php else: ?>
        <span style="padding:4px 10px;color:var(--color-faintest);font-size:0.8rem">‹ <?= e(t('pagination.prev')) ?></span>
    <?php endif; ?>

    <?php foreach ($visible as $i): ?>
        <?php if ($i === '…'): ?>
            <span style="padding:4px 10px;color:var(--color-faint);font-size:0.8rem">…</span>
        <?php elseif ((int)$i === $page): ?>
            <span style="padding:4px 12px;background:var(--color-stone-900);color:var(--color-surface);border-radius:4px;font-variant-numeric:tabular-nums"><?= (int)$i ?></span>
        <?php else: ?>
            <a href="<?= e(url($buildUrl((int)$i))) ?>" style="padding:4px 12px;color:var(--color-muted);text-decoration:none;border-radius:4px;font-variant-numeric:tabular-nums;border:1px solid transparent;transition:background 100ms ease,border-color 100ms ease"
               class="anim-fade-in"
               onfocus="this.style.background='var(--color-stone-100)'" onblur="this.style.background=''"
               ><?= (int)$i ?></a>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($page < $pages): ?>
        <a href="<?= e(url($buildUrl($page + 1))) ?>" class="btn-ghost" style="padding:4px 10px;font-size:0.8rem" aria-label="<?= e(t('pagination.next_page')) ?>"><?= e(t('pagination.next')) ?> ›</a>
    <?php else: ?>
        <span style="padding:4px 10px;color:var(--color-faintest);font-size:0.8rem"><?= e(t('pagination.next')) ?> ›</span>
    <?php endif; ?>
</nav>
