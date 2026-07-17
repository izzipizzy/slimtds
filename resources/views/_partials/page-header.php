<?php
/**
 * Reusable page header (title + counter pill + CTA button).
 *
 * @var string      $title
 * @var ?int        $count        optional counter pill value
 * @var ?string     $ctaLabel     primary action label
 * @var ?string     $ctaHref      primary action href
 * @var ?string     $ctaIcon      inline SVG body for CTA icon (optional)
 * @var ?string     $eyebrow      optional eyebrow text above title
 * @var ?array      $breadcrumb   list of [label, href|null] pairs
 * @var string      $titleClass   default 'page-title'
 */
$title = $title ?? '';
$count = $count ?? null;
$ctaLabel = $ctaLabel ?? null;
$ctaHref  = $ctaHref ?? null;
$ctaIcon  = $ctaIcon ?? null;
$eyebrow  = $eyebrow ?? null;
$breadcrumb = $breadcrumb ?? null;
$titleClass = $titleClass ?? 'page-title';
?>
<?php if (!empty($breadcrumb)): ?>
<nav class="breadcrumb">
    <?php foreach ($breadcrumb as $i => [$label, $href]): ?>
        <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
        <?php if ($href): ?>
            <a href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php else: ?>
            <span><?= e($label) ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="toolbar">
    <div class="toolbar-left">
        <div>
            <?php if ($eyebrow): ?>
                <div class="eyebrow" style="margin-bottom:4px"><?= e($eyebrow) ?></div>
            <?php endif; ?>
            <h1 class="<?= e($titleClass) ?>"><?= e($title) ?></h1>
        </div>
        <?php if ($count !== null): ?>
            <span class="counter-pill"><?= (int)$count ?></span>
        <?php endif; ?>
    </div>
    <?php if ($ctaLabel && $ctaHref): ?>
    <div class="toolbar-right">
        <a href="<?= e($ctaHref) ?>" class="btn">
            <?php if ($ctaIcon): ?>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <?= $ctaIcon ?>
                </svg>
            <?php endif; ?>
            <?= e($ctaLabel) ?>
        </a>
    </div>
    <?php endif; ?>
</div>
