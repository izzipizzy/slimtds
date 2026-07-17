<?php
/** @var list<array{offer:\App\Admin\Repository\Offer, flow_refs:int}> $items */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var string $q */
?>
<?php
$title = t('offers.title');
$count = (int)$total;
$ctaLabel = t('offers.create');
$ctaHref = url('/admin/offers/new');
$ctaIcon = '<path d="M12 5v14M5 12h14"/>';
require __DIR__ . '/../../_partials/page-header.php';
?>

<form method="get" style="margin-bottom:16px">
    <span class="search-input">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M16 16l5 5"/></svg>
        <input type="text" name="q" value="<?= e($q) ?>"
               placeholder="<?= e(t('campaigns.search')) ?>"
               class="input-sm" style="width:300px">
    </span>
</form>

<?php if (empty($items)): ?>
    <?php
    $title = $q !== '' ? t('campaigns.no_results') : t('offers.empty');
    $text = $q !== '' ? null : t('offers.empty_hint');
    $iconBody = '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 11h18M9 7V4h6v3"/>';
    $ctaLabel = $q !== '' ? null : t('offers.create');
    $ctaHref = $q !== '' ? null : url('/admin/offers/new');
    $ctaIcon = '<path d="M12 5v14M5 12h14"/>';
    require __DIR__ . '/../../_partials/empty-state.php';
    ?>
<?php else: ?>
    <div class="tbl-wrap">
        <div class="tbl-scroll">
            <table class="tbl">
                <thead>
                    <tr>
                        <th><?= e(t('offers.name')) ?></th>
                        <th><?= e(t('offers.url')) ?></th>
                        <th style="text-align:right"><?= e(t('offers.payout_default')) ?></th>
                        <th style="text-align:center;width:100px"><?= e(t('offers.usage')) ?></th>
                        <th style="width:80px"><?= e(t('offers.status')) ?></th>
                        <th style="text-align:right;width:140px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $row): $o = $row['offer']; ?>
                        <tr>
                            <td class="tbl-primary">
                                <a href="<?= e(url('/admin/offers/' . $o->id . '/edit')) ?>"
                                   style="color:var(--color-text);text-decoration:none;font-weight:500;border-bottom:1px dashed transparent"
                                   onfocus="this.style.borderBottomColor='var(--color-terra-500)'"
                                   onblur="this.style.borderBottomColor='transparent'"><?= e($o->name) ?></a>
                            </td>
                            <td class="meta-mono" style="max-width:380px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($o->url) ?>"><?= e($o->url) ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;color:var(--color-stone-700)">
                                <?= e($o->payoutDefault ?? '—') ?> <span style="color:var(--color-faint);font-size:0.7rem;font-family:var(--font-mono)"><?= e($o->currency) ?></span>
                            </td>
                            <td style="text-align:center">
                                <?php $refs = (int)$row['flow_refs']; ?>
                                <span class="badge <?= $refs > 0 ? 'badge-info' : 'badge-ghost' ?>" title="<?= e(tn('offers.usage_count', $refs)) ?>">
                                    <?= e(tn('offers.flow_count', $refs)) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $o->isActive ? 'badge-success' : 'badge-ghost' ?>"><?= e($o->isActive ? t('offers.status_active') : t('offers.status_inactive')) ?></span>
                            </td>
                            <td class="row-actions" style="text-align:right;white-space:nowrap">
                                <a href="<?= e(url('/admin/offers/' . $o->id . '/edit')) ?>" class="action-link"><?= e(t('campaigns.edit')) ?></a>
                                <a href="<?= e(url('/admin/offers/' . $o->id . '/delete')) ?>" class="danger-link" style="margin-left:12px"><?= e(t('campaigns.delete')) ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    $baseUrl = '/admin/offers';
    $extraQuery = ['q' => $q !== '' ? $q : null];
    require __DIR__ . '/../../_partials/pagination.php';
    ?>
<?php endif; ?>
