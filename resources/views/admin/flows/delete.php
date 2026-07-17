<?php
/** @var \App\Admin\Repository\Campaign $campaign */
/** @var \App\Admin\Repository\Flow $flow */
/** @var string $csrf_token */
?>
<div style="max-width:460px">
    <h1 class="page-title-sm"><?= e(t('flows.delete')) ?>?</h1>
    <p style="font-size:0.875rem;color:var(--color-stone-600);font-family:var(--font-sans);margin-bottom:24px">
        <strong style="color:var(--color-stone-900)"><?= e($flow->name) ?></strong>
    </p>
    <form method="post" action="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/' . $flow->id . '/delete')) ?>" style="display:flex;gap:10px">
        <?= csrf_field($csrf_token) ?>
        <button type="submit" class="btn" style="background:var(--color-danger);border-color:var(--color-danger-strong)"><?= e(t('flows.delete')) ?></button>
        <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows')) ?>" class="btn-secondary"><?= e(t('form.cancel')) ?></a>
    </form>
</div>
