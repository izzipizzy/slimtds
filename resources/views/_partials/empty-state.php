<?php
/**
 * Designed empty state with icon + title + text + optional CTA.
 *
 * @var string  $title
 * @var ?string $text
 * @var ?string $iconBody   inline SVG path body (24×24 stroke). Default: empty box.
 * @var ?string $ctaLabel
 * @var ?string $ctaHref
 * @var ?string $ctaIcon    inline SVG body
 */
$title = $title ?? '';
$text  = $text ?? null;
$iconBody = $iconBody ?? '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18"/><path d="M9 14h6"/>';
$ctaLabel = $ctaLabel ?? null;
$ctaHref  = $ctaHref ?? null;
$ctaIcon  = $ctaIcon ?? '<path d="M12 5v14M5 12h14"/>';
?>
<div class="empty-state anim-fade-in">
    <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <?= $iconBody ?>
    </svg>
    <div class="empty-state-title"><?= e($title) ?></div>
    <?php if ($text): ?>
        <div class="empty-state-text"><?= e($text) ?></div>
    <?php endif; ?>
    <?php if ($ctaLabel && $ctaHref): ?>
        <div class="empty-state-actions">
            <a href="<?= e($ctaHref) ?>" class="btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <?= $ctaIcon ?>
                </svg>
                <?= e($ctaLabel) ?>
            </a>
        </div>
    <?php endif; ?>
</div>
