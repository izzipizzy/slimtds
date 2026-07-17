<?php
/** @var list<\App\Admin\Repository\Campaign> $items */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var string $q */
/** @var string $app_url */
/** @var array<string,int> $offer_counts */
/** @var array<string,int> $flow_counts */
?>
<div>
    <?php
    $title = t('campaigns.title');
    $count = (int)$total;
    $ctaLabel = t('campaigns.create');
    $ctaHref = url('/admin/campaigns/new');
    $ctaIcon = '<path d="M12 5v14M5 12h14"/>';
    require __DIR__ . '/../../_partials/page-header.php';
    ?>

    <form method="get" style="margin-bottom:16px">
        <span class="search-input">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M16 16l5 5"/></svg>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('campaigns.search')) ?>" class="input-sm" style="width:300px">
        </span>
    </form>

    <?php if (empty($items)): ?>
        <?php
        $title = $q !== '' ? t('campaigns.no_results') : t('campaigns.empty');
        $text = $q !== '' ? null : t('campaigns.empty_hint');
        $iconBody = '<path d="M5 4h11l3 4-3 4H5z"/><path d="M5 4v16"/>';
        $ctaLabel = $q !== '' ? null : t('campaigns.create');
        $ctaHref = $q !== '' ? null : url('/admin/campaigns/new');
        $ctaIcon = '<path d="M12 5v14M5 12h14"/>';
        require __DIR__ . '/../../_partials/empty-state.php';
        ?>
    <?php else: ?>
        <div style="border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface);overflow:hidden">
            <div style="overflow-x:auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th><?= e(t('campaigns.slug')) ?></th>
                            <th><?= e(t('campaigns.name')) ?></th>
                            <th><?= e(t('campaigns.is_active')) ?></th>
                            <th><?= e(t('campaigns.created_at')) ?></th>
                            <th><?= e(t('offers.title')) ?></th>
                            <th><?= e(t('flows.title')) ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $c): ?>
                            <tr>
                                <td class="tbl-primary">
                                    <a href="<?= e(url('/admin/campaigns/' . $c->id . '/edit')) ?>"
                                       style="font-family:var(--font-mono);font-size:0.875rem;color:var(--color-stone-900);text-decoration:none;border-bottom:1px dashed transparent"><?= e($c->slug) ?></a>
                                </td>
                                <td>
                                    <?php $liveUrl = $app_url . '/' . $c->slug; ?>
                                    <div style="display:inline-flex;align-items:center;gap:8px">
                                        <a href="<?= e(url('/admin/campaigns/' . $c->id . '/edit')) ?>"
                                           style="color:var(--color-stone-700);text-decoration:none;font-weight:500"><?= e($c->name) ?></a>
                                        <button type="button"
                                                title="<?= e(t('campaigns.copy_traffic_url') ?? 'Copy traffic URL') ?>: <?= e($liveUrl) ?>"
                                                onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($liveUrl, JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>); window.dispatchEvent(new CustomEvent('toast',{detail:{type:'success',msg:<?= htmlspecialchars(json_encode(t('campaigns.url_copied', ['url' => $liveUrl]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>}}))"
                                                style="background:transparent;border:0;cursor:pointer;color:var(--color-faint);padding:2px;border-radius:3px;display:inline-flex;align-items:center"
                                                aria-label="<?= e(t('campaigns.copy_traffic_url')) ?>">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                            </svg>
                                        </button>
                                        <a href="<?= e(url('/admin/clicks?campaign_id=' . urlencode($c->id))) ?>"
                                           title="<?= e(t('campaigns.view_clicks') ?? 'Clicks for this campaign') ?>"
                                           style="color:var(--color-faint);text-decoration:none;display:inline-flex;align-items:center;padding:2px"
                                           aria-label="<?= e(t('campaigns.view_clicks')) ?>">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <rect x="4" y="13" width="3" height="7"/><rect x="10.5" y="8" width="3" height="12"/><rect x="17" y="4" width="3" height="16"/>
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?= $c->isActive ? 'badge-success' : 'badge-ghost' ?>"><?= $c->isActive ? 'active' : 'inactive' ?></span>
                                </td>
                                <td style="font-family:var(--font-mono);font-size:0.75rem;color:var(--color-stone-400);white-space:nowrap"><?= e($c->createdAt->format('Y-m-d H:i')) ?></td>
                                <td>
                                    <a href="<?= e(url('/admin/campaigns/' . $c->id . '/offers')) ?>"
                                       style="font-size:0.8rem;color:var(--color-stone-500);text-decoration:none;font-variant-numeric:tabular-nums">
                                        <?= tn('offers.count', (int)($offer_counts[$c->id] ?? 0)) ?>
                                    </a>
                                </td>
                                <td>
                                    <a href="<?= e(url('/admin/campaigns/' . $c->id . '/flows')) ?>"
                                       style="font-size:0.8rem;color:var(--color-stone-500);text-decoration:none;font-variant-numeric:tabular-nums">
                                        <?= tn('flows.count', (int)($flow_counts[$c->id] ?? 0)) ?>
                                    </a>
                                </td>
                                <td class="row-actions" style="text-align:right;white-space:nowrap">
                                    <a href="<?= e(url('/admin/campaigns/' . $c->id . '/edit')) ?>" class="action-link"><?= e(t('campaigns.edit')) ?></a>
                                    <a href="<?= e(url('/admin/campaigns/' . $c->id . '/delete')) ?>" class="danger-link" style="margin-left:12px"><?= e(t('campaigns.delete')) ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php
        $baseUrl = '/admin/campaigns';
        $extraQuery = ['q' => $q !== '' ? $q : null];
        require __DIR__ . '/../../_partials/pagination.php';
        ?>
    <?php endif; ?>
</div>
