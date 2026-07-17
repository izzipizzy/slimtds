<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var array<string,mixed> $filters */
/** @var array{approved:array{count:int,payout:string}, pending:array{count:int,payout:string}, hold:array{count:int,payout:string}, rejected:array{count:int,payout:string}} $breakdown */
/** @var list<\App\Admin\Repository\Campaign> $campaigns */
?>
<?php
$title = t('conversions.title');
$count = (int)$total;
require __DIR__ . '/../../_partials/page-header.php';
?>

<!-- Status breakdown — clickable cards -->
<div class="kpi-grid-4" style="margin-bottom:18px">
    <?php foreach (['approved', 'pending', 'hold', 'rejected'] as $st): ?>
        <a class="kpi-card" href="<?= e(url('/admin/conversions?' . http_build_query(array_filter(['campaign_id' => $filters['campaign_id'] ?? null, 'status' => $st], fn ($v) => $v !== null && $v !== '')))) ?>">
            <span class="kpi-eyebrow"><?= e(t('conversions.eyebrow.' . $st)) ?></span>
            <div class="kpi-value"><?= (int)$breakdown[$st]['count'] ?></div>
            <div class="kpi-meta" style="font-family:var(--font-mono)">$<?= e($breakdown[$st]['payout']) ?></div>
        </a>
    <?php endforeach; ?>
</div>

<form method="get" class="filter-bar">
    <div class="filter-field">
        <label class="filter-label"><?= e(t('conversions.filter.campaign')) ?></label>
        <select name="campaign_id" class="input-sm" style="width:220px">
            <option value=""><?= e(t('conversions.filter.any_campaign')) ?></option>
            <?php foreach ($campaigns as $c): ?>
                <option value="<?= e($c->id) ?>" <?= ($filters['campaign_id'] ?? '') === $c->id ? 'selected' : '' ?>><?= e($c->slug) ?> · <?= e($c->name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('conversions.filter.status')) ?></label>
        <select name="status" class="input-sm" style="width:140px">
            <option value=""><?= e(t('conversions.filter.any_status')) ?></option>
            <?php foreach (['approved', 'pending', 'hold', 'rejected'] as $st): ?>
                <option value="<?= $st ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-secondary" style="font-size:0.8rem;height:32px;align-self:flex-end"><?= e(t('conversions.apply')) ?></button>
</form>

<?php if (empty($items)): ?>
    <?php
    $title = t('conversions.empty_title');
    $text = t('conversions.empty_text');
    $iconBody = '<path d="M3 17l5-5 4 4 8-8"/><path d="M14 8h6v6"/>';
    $ctaLabel = t('conversions.view_offers');
    $ctaHref = url('/admin/offers');
    $ctaIcon = '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V4h6v3"/>';
    require __DIR__ . '/../../_partials/empty-state.php';
    ?>
<?php else: ?>
    <div class="tbl-wrap">
        <div class="tbl-scroll">
            <table class="tbl">
                <thead>
                    <tr>
                        <th><?= e(t('conversions.col.time')) ?></th>
                        <th><?= e(t('conversions.col.campaign')) ?></th>
                        <th><?= e(t('conversions.col.offer')) ?></th>
                        <th><?= e(t('conversions.col.click')) ?></th>
                        <th style="text-align:right"><?= e(t('conversions.col.payout')) ?></th>
                        <th><?= e(t('conversions.col.status')) ?></th>
                        <th><?= e(t('conversions.col.source_ip')) ?></th>
                        <th><?= e(t('conversions.col.external')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $r): ?>
                        <tr>
                            <td class="meta-mono" style="white-space:nowrap"><?= e(substr((string)$r['created_at'], 0, 19)) ?></td>
                            <td style="font-family:var(--font-mono);font-size:0.82rem"><?= e((string)($r['campaign_slug'] ?? '?')) ?></td>
                            <td><?= e((string)($r['offer_name'] ?? '—')) ?></td>
                            <td>
                                <a href="<?= e(url('/admin/clicks?click_id=' . urlencode((string)$r['click_id']))) ?>"
                                   class="meta-mono" style="text-decoration:none;color:var(--color-faint);border-bottom:1px dashed var(--color-faintest)"
                                   title="<?= e((string)$r['click_id']) ?>"><?= e(substr((string)$r['click_id'], 0, 8)) ?>…</a>
                            </td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e((string)$r['payout']) ?> <span style="color:var(--color-faint);font-size:0.7rem"><?= e((string)$r['currency']) ?></span></td>
                            <td>
                                <?php $st = (string)$r['status']; ?>
                                <span class="badge <?= match ($st) { 'approved' => 'badge-success', 'pending' => 'badge-warn', 'hold' => 'badge-info', 'rejected' => 'badge-danger', default => 'badge-neutral' } ?>"><?= e($st) ?></span>
                            </td>
                            <td class="meta-mono" style="white-space:nowrap"<?php if (!empty($r['raw_query'])): ?> title="<?= e((string)$r['raw_query']) ?>"<?php endif; ?>><?= e((string)($r['source_ip'] ?? '')) ?: '—' ?></td>
                            <td class="meta-mono"><?= e((string)($r['external_id'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    $baseUrl = '/admin/conversions';
    $extraQuery = array_filter([
        'campaign_id' => $filters['campaign_id'] ?? null,
        'status'      => $filters['status']      ?? null,
    ], fn ($v) => $v !== null && $v !== '');
    require __DIR__ . '/../../_partials/pagination.php';
    ?>
<?php endif; ?>
