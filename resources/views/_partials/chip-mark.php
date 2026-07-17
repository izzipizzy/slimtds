<?php
/**
 * Reusable chip-mark SVG. Two variants:
 *   $variant = 'dark'  — for dark backgrounds (login dark side, footer accent)
 *   $variant = 'light' — for light backgrounds (sidebar, default)
 *
 * @var int    $size     pixel size, default 24
 * @var string $variant  'light' | 'dark'
 */
$size    = $size    ?? 24;
$variant = $variant ?? 'light';

if ($variant === 'dark') {
    $bg     = '#1a1917';   // stone-900
    $chip   = '#fafaf7';   // stone-50
    $stroke = '#b54f17';   // terra-500
    $letter = '#b54f17';
    $trace  = '#d9713e';   // terra-300
} else {
    $bg     = 'transparent';
    $chip   = '#1a1917';   // dark chip on light bg
    $stroke = '#b54f17';
    $letter = '#fafaf7';   // light letter on dark chip
    $trace  = '#b54f17';   // terra-500 on light bg for stronger contrast
}
?>
<svg width="<?= (int)$size ?>" height="<?= (int)$size ?>" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <?php if ($variant === 'dark'): ?>
        <rect width="32" height="32" rx="5" fill="<?= e($bg) ?>"/>
    <?php endif; ?>
    <rect x="9" y="9" width="14" height="14" rx="1.5" fill="<?= e($chip) ?>" stroke="<?= e($stroke) ?>" stroke-width="0.6"/>
    <text x="16" y="20.4" text-anchor="middle" font-family="Bricolage Grotesque, Georgia, serif" font-size="11" font-weight="700" fill="<?= e($letter) ?>">S</text>
    <!-- traces -->
    <g fill="<?= e($trace) ?>">
        <rect x="11.7" y="3" width="0.8" height="6"/>
        <rect x="15.6" y="2" width="0.8" height="7"/>
        <rect x="19.5" y="3" width="0.8" height="6"/>
        <rect x="11.7" y="23" width="0.8" height="6"/>
        <rect x="15.6" y="23" width="0.8" height="7"/>
        <rect x="19.5" y="23" width="0.8" height="6"/>
        <rect x="3" y="11.7" width="6" height="0.8"/>
        <rect x="2" y="15.6" width="7" height="0.8"/>
        <rect x="3" y="19.5" width="6" height="0.8"/>
        <rect x="23" y="11.7" width="6" height="0.8"/>
        <rect x="23" y="15.6" width="7" height="0.8"/>
        <rect x="23" y="19.5" width="6" height="0.8"/>
        <circle cx="12.1" cy="2.4" r="0.7"/>
        <circle cx="16" cy="1.6" r="0.7"/>
        <circle cx="19.9" cy="2.4" r="0.7"/>
        <circle cx="12.1" cy="29.6" r="0.7"/>
        <circle cx="16" cy="30.4" r="0.7"/>
        <circle cx="19.9" cy="29.6" r="0.7"/>
        <circle cx="2.4" cy="12.1" r="0.7"/>
        <circle cx="1.6" cy="16" r="0.7"/>
        <circle cx="2.4" cy="19.9" r="0.7"/>
        <circle cx="29.6" cy="12.1" r="0.7"/>
        <circle cx="30.4" cy="16" r="0.7"/>
        <circle cx="29.6" cy="19.9" r="0.7"/>
    </g>
</svg>
