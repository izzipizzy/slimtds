<?php
/** @var \App\Admin\Repository\Campaign $campaign */
/** @var list<array{hour:string,clicks:int,uniq:int,bot:int}> $timeline */
/** @var array<string,mixed> $summary */
/** @var string $window */
?>
<div style="margin-bottom:24px">
    <nav style="font-size:0.8rem;color:var(--color-stone-400);margin-bottom:6px">
        <a href="<?= e(url('/admin/campaigns')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e(t('campaigns.title')) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/edit')) ?>" style="color:var(--color-stone-400);text-decoration:none;font-family:var(--font-mono)"><?= e($campaign->slug) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <span><?= e(t('statistics.breadcrumb')) ?></span>
    </nav>
    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap">
        <h1 class="page-title">
            <?= e(t('statistics.campaign_title', ['name' => $campaign->name])) ?>
        </h1>
        <form method="get" style="display:flex;gap:8px;font-size:0.875rem">
            <select name="window" class="input" style="width:100px">
                <option value="24h" <?= $window === '24h' ? 'selected' : '' ?>>24h</option>
                <option value="7d"  <?= $window === '7d'  ? 'selected' : '' ?>>7d</option>
                <option value="30d" <?= $window === '30d' ? 'selected' : '' ?>>30d</option>
            </select>
            <button type="submit" class="btn-secondary" style="font-size:0.8rem"><?= e(t('statistics.apply')) ?></button>
        </form>
    </div>
</div>

<!-- KPIs -->
<div class="adapt-stack" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px">
    <?php foreach ([
        [t('statistics.kpi.clicks'),      (int)$summary['clicks']],
        [t('statistics.kpi.unique'),      (int)$summary['uniq']],
        [t('statistics.kpi.conversions'), (int)$summary['approved']],
        [t('statistics.kpi.payout'),      '$' . $summary['payout']],
    ] as [$label, $val]): ?>
        <div style="padding:12px 16px;border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface)">
            <div style="font-size:0.7rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-stone-500);margin-bottom:4px"><?= e($label) ?></div>
            <div style="font-family:var(--font-display);font-size:1.5rem;font-weight:600;font-variant-numeric:tabular-nums"><?= e((string)$val) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Chart -->
<div style="padding:8px;border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface)">
    <div x-data="statsChart({ points: <?= e(json_encode($timeline, JSON_UNESCAPED_SLASHES)) ?> })" style="width:100%;height:360px"></div>
</div>

<div style="margin-top:16px;font-size:0.8rem;color:var(--color-stone-500);font-family:var(--font-sans)">
    <?= e(t('statistics.cr')) ?> <strong><?= e((string)$summary['cr']) ?>%</strong> &middot; <?= e(t('statistics.epc')) ?> <strong>$<?= e((string)$summary['epc']) ?></strong> &middot; <?= e(t('statistics.bots')) ?> <?= (int)$summary['bots'] ?>
</div>
