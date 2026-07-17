<?php
/** @var \App\Admin\Repository\Campaign $campaign */
/** @var list<\App\Admin\Repository\Offer> $items */
?>
<div>
    <!-- Breadcrumb -->
    <nav style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans);margin-bottom:20px">
        <a href="<?= e(url('/admin/campaigns')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e(t('campaigns.title')) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/edit')) ?>" style="color:var(--color-stone-400);text-decoration:none;font-family:var(--font-mono)"><?= e($campaign->slug) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <span><?= e(t('offers.title')) ?></span>
    </nav>

    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:24px">
        <h1 class="page-title-sm">
            <?= e(t('offers.list_title', ['campaign' => $campaign->name])) ?>
        </h1>
        <a href="<?= e(url('/admin/offers/new')) ?>" class="btn-secondary"><?= e(t('offers.create')) ?></a>
    </div>

    <p style="font-size:0.8rem;color:var(--color-stone-500);font-family:var(--font-sans);margin-bottom:16px">
        <?= e(t('offers.campaign_view_hint')) ?>
    </p>

    <?php if (empty($items)): ?>
        <div style="padding:32px 0;font-size:0.875rem;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(t('offers.empty_in_campaign')) ?></div>
    <?php else: ?>
        <div style="border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface);overflow:hidden">
            <div style="overflow-x:auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th><?= e(t('offers.name')) ?></th>
                            <th><?= e(t('offers.url')) ?></th>
                            <th><?= e(t('offers.payout_default')) ?></th>
                            <th><?= e(t('offers.is_active')) ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $o): ?>
                            <tr>
                                <td class="tbl-primary">
                                    <a href="<?= e(url('/admin/offers/' . $o->id . '/edit')) ?>"
                                       style="color:var(--color-stone-900);text-decoration:none;border-bottom:1px dashed transparent"><?= e($o->name) ?></a>
                                </td>
                                <td style="font-family:var(--font-mono);font-size:0.75rem;color:var(--color-stone-400);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($o->url) ?>"><?= e($o->url) ?></td>
                                <td style="font-variant-numeric:tabular-nums;font-size:0.875rem;white-space:nowrap">
                                    <?= e($o->payoutDefault ?? '—') ?> <span style="font-size:0.75rem;color:var(--color-stone-400)"><?= e($o->currency) ?></span>
                                </td>
                                <td>
                                    <?php if ($o->isActive): ?>
                                        <span class="status-dot status-dot-active"></span>
                                    <?php else: ?>
                                        <span class="status-dot status-dot-inactive"></span>
                                    <?php endif; ?>
                                </td>
                                <td class="row-actions" style="text-align:right;white-space:nowrap">
                                    <a href="<?= e(url('/admin/offers/' . $o->id . '/edit')) ?>"
                                       style="font-size:0.8rem;color:var(--color-stone-500);text-decoration:none"><?= e(t('campaigns.edit')) ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
