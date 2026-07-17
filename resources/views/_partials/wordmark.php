<?php
/**
 * Composite brand mark: chip + slim/TDS wordmark.
 *
 * @var int    $size       chip size, default 24
 * @var string $variant    'light' | 'dark'
 * @var string $textSize   tailwind/css size token, default '1.25rem'
 * @var bool   $tagline    show tagline below
 */
$size = $size ?? 24;
$variant = $variant ?? 'light';
$textSize = $textSize ?? '1.25rem';
$tagline = $tagline ?? false;
$accent = $variant === 'dark' ? 'var(--color-terra-300)' : 'var(--color-terra-500)';
$text = $variant === 'dark' ? 'var(--color-stone-50)' : 'var(--color-stone-900)';
?>
<span style="display:inline-flex;align-items:center;gap:10px;text-decoration:none">
    <?php require __DIR__ . '/chip-mark.php'; ?>
    <span style="display:inline-flex;flex-direction:column;line-height:1">
        <span style="font-family:var(--font-display);font-size:<?= e($textSize) ?>;font-weight:600;color:<?= e($text) ?>;letter-spacing:-0.02em">slim<span style="color:<?= e($accent) ?>">/</span>TDS</span>
        <?php if ($tagline): ?>
            <span style="font-family:var(--font-mono);font-size:0.62rem;color:<?= e($variant === 'dark' ? 'var(--color-stone-500)' : 'var(--color-stone-400)') ?>;letter-spacing:0.18em;text-transform:uppercase;margin-top:3px">traffic distribution</span>
        <?php endif; ?>
    </span>
</span>
