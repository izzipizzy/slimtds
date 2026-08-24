<?php
/**
 * Running build + update chip. Shared by both layouts.
 *
 * `$__update__` is a layout global injected by View; it is null when the
 * status service is unavailable, which renders exactly like the `unknown`
 * state. The version is shown whenever the build identity is known — the state
 * only decides the chip.
 *
 * @var \App\Shared\Version\UpdateVerdict|null $__update__
 */
$u = $__update__ ?? null;

$label  = $u?->versionLabel ?? 'unknown';
$commit = $u?->commit ?? '';
$state  = $u?->state ?? 'unknown';
?>
<span style="font-family:var(--font-mono);color:var(--color-faintest)"><?= e($label) ?><?php
    if ($commit !== ''): ?> · <?= e($commit) ?><?php endif; ?></span>

<?php if ($state === 'behind' && $u?->chipUrl !== null): ?>
    <a href="<?= e($u->chipUrl) ?>" target="_blank" rel="noopener"
       title="<?= e(t('version.behind_title')) ?><?php
           if ($u->publishedAt !== null): ?> · <?= e($u->publishedAt) ?><?php endif; ?>"
       style="font-family:var(--font-mono);font-size:0.66rem;letter-spacing:0.03em;padding:1px 7px;border-radius:3px;
              text-decoration:none;background:var(--color-terra-400);color:#fff;border:1px solid var(--color-terra-500)">
        ↑ <?= e((string)$u->latestVersion) ?>
    </a>
    <?php if ($u->guideUrl !== null): ?>
        <?php /* The chip says what changed; this says how to apply it. */ ?>
        <a href="<?= e($u->guideUrl) ?>" target="_blank" rel="noopener"
           style="font-family:var(--font-sans);font-size:0.68rem;letter-spacing:0.03em;text-decoration:underline;
                  text-underline-offset:2px;color:var(--color-muted)"><?= e(t('version.how_to_update')) ?></a>
    <?php endif; ?>
<?php elseif ($state === 'source_build'): ?>
    <span title="<?= e(t('version.source_title')) ?>"
          style="font-family:var(--font-mono);font-size:0.66rem;letter-spacing:0.03em;padding:1px 7px;border-radius:3px;
                 border:1px solid var(--color-border);color:var(--color-faint)">src</span>
<?php elseif ($state === 'stale'): ?>
    <span title="<?= e(t('version.stale_title')) ?>"
          style="font-family:var(--font-mono);font-size:0.66rem;letter-spacing:0.03em;padding:1px 7px;border-radius:3px;
                 border:1px solid var(--color-border);color:var(--color-faint)">stale?</span>
<?php endif; ?>
