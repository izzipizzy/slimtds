<?php
/** @var \App\Admin\Repository\Campaign $campaign */
/** @var list<\App\Admin\Repository\Flow> $items */
/** @var array<string,\App\Admin\Repository\Offer> $offer_map */

// Render filter groups as compact code-pills (matches the campaign workspace card view).
// AND inside one group, OR between groups.
$opSym = ['eq' => '=', 'neq' => '≠', 'in' => '∈', 'not_in' => '∉', 'gt' => '>', 'lt' => '<', 'between' => '↔', 'regex' => '~', 'exists' => '?'];
$renderFilters = function (array $groups) use ($opSym): string {
    if ($groups === []) return '<span style="color:var(--color-stone-300);font-family:var(--font-sans)">— any —</span>';
    $groupHtml = [];
    foreach ($groups as $group) {
        $cond = [];
        foreach ($group as $c) {
            $field = (string)($c['field'] ?? '');
            $op    = (string)($c['op'] ?? 'eq');
            $val   = $c['value'] ?? '';
            if (is_array($val)) $val = implode(',', array_map('strval', $val));
            if (is_bool($val))  $val = $val ? '1' : '0';
            $sym = $opSym[$op] ?? $op;
            $cond[] = '<span style="font-family:var(--font-mono);font-size:0.72rem;color:var(--color-stone-700)">'
                . htmlspecialchars($field, ENT_QUOTES) . ' '
                . '<span style="color:var(--color-terra-500)">' . htmlspecialchars($sym, ENT_QUOTES) . '</span> '
                . htmlspecialchars((string)$val, ENT_QUOTES) . '</span>';
        }
        $groupHtml[] = '<span style="display:inline-block;background:var(--color-stone-100);padding:1px 6px;border-radius:3px;margin-right:3px">' . implode(' <span style="color:var(--color-stone-400)">·</span> ', $cond) . '</span>';
    }
    return implode('<span style="font-family:var(--font-mono);color:var(--color-stone-400);font-size:0.7rem;margin:0 4px">OR</span>', $groupHtml);
};
?>
<div>
    <!-- Breadcrumb -->
    <nav style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans);margin-bottom:20px">
        <a href="<?= e(url('/admin/campaigns')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e(t('campaigns.title')) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <span style="font-family:var(--font-mono)"><?= e($campaign->slug) ?></span>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <span><?= e(t('flows.title')) ?></span>
    </nav>

    <!-- Page header -->
    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:24px">
        <h1 class="page-title-sm">
            <?= e(t('flows.list_title', ['campaign' => $campaign->name])) ?>
        </h1>
        <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/new')) ?>" class="btn">
            <?= e(t('flows.create')) ?>
        </a>
    </div>

    <?php if (empty($items)): ?>
        <div style="padding:32px 0;font-size:0.875rem;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(t('flows.count.zero')) ?></div>
    <?php else: ?>
        <div style="border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface);overflow:hidden">
            <div style="overflow-x:auto">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th style="width:48px">#</th>
                            <th><?= e(t('flows.name')) ?></th>
                            <th><?= e(t('flows.filters') ?? 'Filters') ?></th>
                            <th><?= e(t('flows.schema')) ?></th>
                            <th><?= e(t('flows.target')) ?></th>
                            <th style="width:72px"><?= e(t('flows.weight')) ?></th>
                            <th style="width:56px"><?= e(t('flows.is_active')) ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $i => $f): $isFirst = $i === 0; $isLast = $i === count($items) - 1; ?>
                            <tr>
                                <td style="white-space:nowrap;font-variant-numeric:tabular-nums;color:var(--color-stone-400);font-size:0.8rem;font-family:var(--font-mono)">
                                    <span style="display:inline-block;min-width:18px;text-align:right;margin-right:4px"><?= (int)$f->position ?></span>
                                    <form method="post" action="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/' . $f->id . '/move?dir=up')) ?>" style="display:inline-block;margin:0">
                                        <?= csrf_field($csrf_token) ?>
                                        <button type="submit" class="btn-ghost" <?= $isFirst ? 'disabled' : '' ?> aria-label="<?= e(t('a11y.move_up')) ?>" title="<?= e(t('a11y.move_up')) ?>" style="padding:1px 4px;line-height:1;font-size:0.7rem;color:<?= $isFirst ? 'var(--color-stone-300)' : 'var(--color-stone-500)' ?>">▲</button>
                                    </form>
                                    <form method="post" action="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/' . $f->id . '/move?dir=down')) ?>" style="display:inline-block;margin:0">
                                        <?= csrf_field($csrf_token) ?>
                                        <button type="submit" class="btn-ghost" <?= $isLast ? 'disabled' : '' ?> aria-label="<?= e(t('a11y.move_down')) ?>" title="<?= e(t('a11y.move_down')) ?>" style="padding:1px 4px;line-height:1;font-size:0.7rem;color:<?= $isLast ? 'var(--color-stone-300)' : 'var(--color-stone-500)' ?>">▼</button>
                                    </form>
                                </td>
                                <td class="tbl-primary">
                                    <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/' . $f->id . '/edit')) ?>"
                                       style="color:var(--color-stone-900);text-decoration:none;border-bottom:1px dashed transparent"><?= e($f->name) ?></a>
                                </td>
                                <td style="font-size:0.8rem"><?= $renderFilters($f->filters ?? []) ?></td>
                                <td style="font-size:0.8rem;color:var(--color-stone-600)"><?= e(t('schemas.' . $f->schemaId)) ?></td>
                                <td style="font-size:0.8rem;color:var(--color-stone-600)">
                                    <?php if ($f->targetType === 'offers' && !empty($f->targetOffers)): ?>
                                        <?php foreach ($f->targetOffers as $to): ?>
                                            <?php $oName = $offer_map[$to['offer_id'] ?? ''] ?? null; ?>
                                            <?php if ($oName): ?>
                                                <span style="display:inline-flex;align-items:center;gap:4px;background:var(--color-stone-100);padding:1px 6px;border-radius:3px;font-size:0.75rem;margin-right:4px;font-family:var(--font-sans)">
                                                    <?= e($oName->name) ?><span style="color:var(--color-stone-400);font-family:var(--font-mono)"><?= (int)($to['weight'] ?? 0) ?>%</span>
                                                </span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color:var(--color-stone-300)">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-variant-numeric:tabular-nums;font-size:0.8rem;font-family:var(--font-mono)"><?= (int)$f->weight ?></td>
                                <td>
                                    <?php if ($f->isActive): ?>
                                        <span class="status-dot status-dot-active"></span>
                                    <?php else: ?>
                                        <span class="status-dot status-dot-inactive"></span>
                                    <?php endif; ?>
                                </td>
                                <td class="row-actions" style="text-align:right;white-space:nowrap">
                                    <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/' . $f->id . '/edit')) ?>"
                                       style="font-size:0.8rem;color:var(--color-stone-500);text-decoration:none;margin-right:12px"><?= e(t('campaigns.edit')) ?></a>
                                    <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/' . $f->id . '/delete')) ?>"
                                       style="font-size:0.8rem;color:var(--color-stone-400);text-decoration:none"><?= e(t('campaigns.delete')) ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
