<?php
/** @var \App\Admin\Repository\Campaign $campaign */
/** @var string $app_url */
/** @var list<array<string,mixed>> $recent */
/** @var array{day:int, week:int, month:int} $counts */
$snippet  = '<script async src="' . $app_url . '/p.js?c=' . $campaign->slug . '"></script>';
$customEv = "window.slimTDS && slimTDS.track('purchase', { amount: 99, sku: 'X1' })";
?>
<div style="margin-bottom:24px;display:flex;align-items:baseline;justify-content:space-between">
    <div>
        <nav style="font-size:0.8rem;color:var(--color-stone-400);margin-bottom:6px">
            <a href="<?= e(url('/admin/campaigns')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e(t('campaigns.title')) ?></a>
            <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
            <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/edit')) ?>" style="color:var(--color-stone-400);text-decoration:none;font-family:var(--font-mono)"><?= e($campaign->slug) ?></a>
            <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
            <span><?= e(t('campaigns.pixel_crumb')) ?></span>
        </nav>
        <h1 class="page-title"><?= e(t('campaigns.tab_pixel')) ?> &middot; <?= e($campaign->name) ?></h1>
    </div>
</div>

<!-- KPI counts -->
<div class="adapt-stack" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:32px">
    <div style="padding:14px 16px;border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface)">
        <div style="font-size:0.7rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-stone-500);margin-bottom:4px"><?= e(t('campaigns.pixel_last_24h')) ?></div>
        <div style="font-family:var(--font-display);font-size:1.5rem;font-weight:600;font-variant-numeric:tabular-nums"><?= (int)$counts['day'] ?></div>
    </div>
    <div style="padding:14px 16px;border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface)">
        <div style="font-size:0.7rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-stone-500);margin-bottom:4px"><?= e(t('campaigns.pixel_last_7d')) ?></div>
        <div style="font-family:var(--font-display);font-size:1.5rem;font-weight:600;font-variant-numeric:tabular-nums"><?= (int)$counts['week'] ?></div>
    </div>
    <div style="padding:14px 16px;border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface)">
        <div style="font-size:0.7rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-stone-500);margin-bottom:4px"><?= e(t('campaigns.pixel_last_30d')) ?></div>
        <div style="font-family:var(--font-display);font-size:1.5rem;font-weight:600;font-variant-numeric:tabular-nums"><?= (int)$counts['month'] ?></div>
    </div>
</div>

<!-- Embed snippet -->
<div style="margin-bottom:32px">
    <h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:600;margin-bottom:12px"><?= e(t('campaigns.pixel_embed')) ?></h2>
    <div style="display:flex;gap:8px;align-items:flex-start">
        <pre style="flex:1;margin:0;padding:14px 16px;border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-stone-50);font-family:var(--font-mono);font-size:0.8rem;color:var(--color-stone-900);overflow-x:auto"><?= htmlspecialchars($snippet, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></pre>
        <button type="button" onclick="navigator.clipboard.writeText(<?= e(json_encode($snippet)) ?>); this.textContent='✓'; setTimeout(()=>this.textContent=<?= htmlspecialchars(json_encode(t('campaigns.copy'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,1500)" class="btn-secondary" style="font-size:0.8rem"><?= e(t('campaigns.copy')) ?></button>
    </div>
</div>

<!-- Custom event snippet -->
<div style="margin-bottom:32px">
    <h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:600;margin-bottom:12px"><?= e(t('campaigns.pixel_custom_event')) ?></h2>
    <div style="display:flex;gap:8px;align-items:flex-start">
        <pre style="flex:1;margin:0;padding:14px 16px;border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-stone-50);font-family:var(--font-mono);font-size:0.8rem"><?= htmlspecialchars($customEv, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></pre>
        <button type="button" onclick="navigator.clipboard.writeText(<?= e(json_encode($customEv)) ?>); this.textContent='✓'; setTimeout(()=>this.textContent=<?= htmlspecialchars(json_encode(t('campaigns.copy'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,1500)" class="btn-secondary" style="font-size:0.8rem"><?= e(t('campaigns.copy')) ?></button>
    </div>
</div>

<!-- Recent events table -->
<h2 style="font-family:var(--font-display);font-size:1.1rem;font-weight:600;margin-bottom:12px"><?= e(t('campaigns.pixel_recent_title')) ?></h2>
<?php if (empty($recent)): ?>
    <div style="padding:24px;border:1px dashed var(--color-stone-200);border-radius:6px;background:var(--color-surface);text-align:center;color:var(--color-stone-400);font-size:0.875rem"><?= e(t('campaigns.pixel_no_events')) ?></div>
<?php else: ?>
    <div style="border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface);overflow:hidden">
        <table class="tbl">
            <thead><tr>
                <th><?= e(t('campaigns.pixel_col_time')) ?></th><th><?= e(t('campaigns.pixel_col_event')) ?></th><th><?= e(t('campaigns.pixel_col_url')) ?></th><th><?= e(t('campaigns.pixel_col_visitor')) ?></th><th><?= e(t('campaigns.pixel_col_geo')) ?></th><th><?= e(t('campaigns.pixel_col_device')) ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td style="font-family:var(--font-mono);font-size:0.75rem;color:var(--color-stone-500);white-space:nowrap"><?= e(substr((string)$r['created_at'], 0, 19)) ?></td>
                        <td style="font-family:var(--font-mono);font-size:0.8rem"><?= e((string)$r['event_name']) ?></td>
                        <td style="font-size:0.8rem;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e((string)($r['page_url'] ?? '')) ?>"><?= e((string)($r['page_url'] ?? '—')) ?></td>
                        <td style="font-family:var(--font-mono);font-size:0.7rem;color:var(--color-stone-400)"><?= e(substr((string)$r['visitor_uuid'], 0, 8)) ?>…</td>
                        <td style="font-size:0.75rem;color:var(--color-stone-600)"><?= e((string)($r['country'] ?? '')) ?> <?= e((string)($r['city'] ?? '')) ?></td>
                        <td style="font-size:0.75rem;color:var(--color-stone-600)"><?= e((string)($r['screen_w'] ?? '?')) ?>×<?= e((string)($r['screen_h'] ?? '?')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
