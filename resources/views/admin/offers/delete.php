<?php
/** @var \App\Admin\Repository\Offer $offer */
/** @var int $flow_refs */
/** @var string $csrf_token */
?>
<div style="max-width:460px">
    <h1 class="page-title-sm"><?= e(t('offers.delete')) ?>?</h1>
    <p style="font-size:0.875rem;color:var(--color-stone-600);font-family:var(--font-sans);margin-bottom:6px">
        <strong style="color:var(--color-stone-900)"><?= e($offer->name) ?></strong>
    </p>
    <p style="font-size:0.75rem;margin-bottom:16px">
        <code style="font-family:var(--font-mono);background:var(--color-stone-100);padding:2px 7px;border-radius:3px;color:var(--color-stone-500)"><?= e($offer->url) ?></code>
    </p>
    <?php if ($flow_refs > 0): ?>
        <p style="font-size:0.8rem;color:var(--color-danger-strong);font-family:var(--font-sans);margin-bottom:24px;padding:10px 12px;background:var(--color-danger-bg);border:1px solid var(--color-danger-border);border-radius:4px">
            <?= e(tn('offers.delete_warn_refs', $flow_refs)) ?>
        </p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/admin/offers/' . $offer->id . '/delete')) ?>" style="display:flex;gap:10px">
        <?= csrf_field($csrf_token) ?>
        <button type="submit" class="btn" style="background:var(--color-danger);border-color:var(--color-danger-strong)"><?= e(t('offers.delete')) ?></button>
        <a href="<?= e(url('/admin/offers')) ?>" class="btn-secondary"><?= e(t('form.cancel')) ?></a>
    </form>
</div>
