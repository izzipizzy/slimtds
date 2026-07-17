<?php
/** @var list<array{flow:\App\Admin\Repository\Flow, campaign_slug:string, campaign_name:string}> $items */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var string $q */
/** @var list<\App\Admin\Repository\Campaign> $campaigns */
?>
<div class="toolbar">
    <div class="toolbar-left">
        <h1 class="page-title"><?= e(t('flows.title')) ?></h1>
        <span class="counter-pill"><?= (int)$total ?></span>
    </div>
    <?php if (!empty($campaigns)): ?>
    <div class="toolbar-right" x-data="{ open: false }" style="position:relative">
        <button type="button" @click="open = !open" class="btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            <?= e(t('flows.create')) ?>
            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="opacity:0.7"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <div x-show="open" x-cloak @click.away="open = false" class="anim-fade-in"
             style="position:absolute;right:0;top:calc(100% + 6px);min-width:260px;max-height:340px;overflow-y:auto;background:var(--color-surface);border:1px solid var(--color-border);border-radius:6px;box-shadow:0 12px 28px -8px rgba(0,0,0,0.18);z-index:20">
            <div class="eyebrow" style="padding:10px 14px 6px"><?= e(t('campaigns.title')) ?></div>
            <?php foreach ($campaigns as $c): ?>
                <a href="<?= e(url('/admin/campaigns/' . $c->id . '/flows/new')) ?>"
                   style="display:flex;align-items:baseline;justify-content:space-between;padding:9px 14px;font-family:var(--font-sans);font-size:0.85rem;color:var(--color-text);text-decoration:none;border-top:1px solid var(--color-border-soft)">
                    <span><?= e($c->name) ?></span>
                    <span style="font-family:var(--font-mono);font-size:0.7rem;color:var(--color-faint)"><?= e($c->slug) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<form method="get" style="margin-bottom:16px">
    <span class="search-input">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M16 16l5 5"/></svg>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('campaigns.search')) ?>" class="input-sm" style="width:300px">
    </span>
</form>

<?php if (empty($items)): ?>
    <?php
    $title = $q !== '' ? t('campaigns.no_results') : t('flows.count.zero');
    $text = $q !== '' ? null : t('flows.empty_hint');
    $iconBody = '<circle cx="6" cy="6" r="2.2"/><circle cx="18" cy="12" r="2.2"/><circle cx="6" cy="18" r="2.2"/><path d="M8 7l8 4M8 17l8-4"/>';
    $ctaLabel = null; $ctaHref = null; $ctaIcon = null;
    require __DIR__ . '/../../_partials/empty-state.php';
    ?>
<?php else: ?>
    <div class="tbl-wrap">
        <div class="tbl-scroll">
            <table class="tbl">
                <thead>
                    <tr>
                        <th><?= e(t('flows.name')) ?></th>
                        <th><?= e(t('campaigns.title')) ?></th>
                        <th><?= e(t('flows.schema')) ?></th>
                        <th style="width:80px"><?= e(t('flows.status')) ?></th>
                        <th style="text-align:right;width:140px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $row): $f = $row['flow']; ?>
                        <tr>
                            <td class="tbl-primary">
                                <a href="<?= e(url('/admin/campaigns/' . $f->campaignId . '/flows/' . $f->id . '/edit')) ?>"
                                   style="color:var(--color-text);text-decoration:none;font-weight:500;border-bottom:1px dashed transparent"><?= e($f->name) ?></a>
                            </td>
                            <td>
                                <a href="<?= e(url('/admin/campaigns/' . $f->campaignId . '/edit')) ?>"
                                   style="color:var(--color-text);text-decoration:none;font-weight:500"><?= e($row['campaign_name']) ?></a>
                                <span style="color:var(--color-faint);font-family:var(--font-mono);font-size:0.72rem;margin-left:6px"><?= e($row['campaign_slug']) ?></span>
                            </td>
                            <td style="color:var(--color-stone-700);font-size:0.85rem"><?= e(t('schemas.' . $f->schemaId)) ?></td>
                            <td><span class="badge <?= $f->isActive ? 'badge-success' : 'badge-ghost' ?>"><?= e($f->isActive ? t('flows.status_active') : t('flows.status_inactive')) ?></span></td>
                            <td class="row-actions" style="text-align:right;white-space:nowrap">
                                <a href="<?= e(url('/admin/campaigns/' . $f->campaignId . '/flows/' . $f->id . '/edit')) ?>" class="action-link"><?= e(t('campaigns.edit')) ?></a>
                                <a href="<?= e(url('/admin/campaigns/' . $f->campaignId . '/flows/' . $f->id . '/delete')) ?>" class="danger-link" style="margin-left:12px"><?= e(t('campaigns.delete')) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    $baseUrl = '/admin/flows';
    $extraQuery = ['q' => $q !== '' ? $q : null];
    require __DIR__ . '/../../_partials/pagination.php';
    ?>
<?php endif; ?>
