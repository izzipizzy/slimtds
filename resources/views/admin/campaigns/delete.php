<?php
/** @var \App\Admin\Repository\Campaign $campaign */
/** @var string $csrf_token */
?>
<div style="max-width:460px">
    <h1 class="page-title-sm"><?= e(t('campaigns.delete')) ?>?</h1>
    <p style="font-size:0.875rem;color:var(--color-stone-600);font-family:var(--font-sans);margin-bottom:24px">
        <code style="font-family:var(--font-mono);font-size:0.8rem;background:var(--color-stone-100);padding:2px 7px;border-radius:3px"><?= e($campaign->slug) ?></code>
        &mdash; <?= e($campaign->name) ?>
    </p>
    <form method="post" action="<?= e(url('/admin/campaigns/' . $campaign->id . '/delete')) ?>" style="display:flex;gap:10px">
        <?= csrf_field($csrf_token) ?>
        <button type="submit" class="btn" style="background:var(--color-danger);border-color:var(--color-danger-strong)"><?= e(t('campaigns.delete')) ?></button>
        <a href="<?= e(url('/admin/campaigns')) ?>" class="btn-secondary"><?= e(t('form.cancel')) ?></a>
    </form>
</div>
