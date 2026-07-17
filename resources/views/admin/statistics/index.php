<?php
/** @var list<array{hour:string,clicks:int,uniq:int,bot:int}> $timeline */
/** @var array<string,mixed> $summary */
/** @var string $window */
/** @var ?string $campaign_id */
/** @var list<\App\Admin\Repository\Campaign> $campaigns */
?>
<div style="margin-bottom:24px;display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <h1 class="page-title"><?= e(t('statistics.title')) ?></h1>
    <form method="get" style="display:flex;gap:8px;font-size:0.875rem">
        <select name="campaign_id" class="input" style="width:200px">
            <option value=""><?= e(t('statistics.any')) ?></option>
            <?php foreach ($campaigns as $c): ?>
                <option value="<?= e($c->id) ?>" <?= ($campaign_id === $c->id) ? 'selected' : '' ?>><?= e($c->slug) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="window" class="input" style="width:100px">
            <option value="24h" <?= $window === '24h' ? 'selected' : '' ?>>24h</option>
            <option value="7d"  <?= $window === '7d'  ? 'selected' : '' ?>>7d</option>
            <option value="30d" <?= $window === '30d' ? 'selected' : '' ?>>30d</option>
        </select>
        <button type="submit" class="btn-secondary" style="font-size:0.8rem"><?= e(t('statistics.apply')) ?></button>
    </form>
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
