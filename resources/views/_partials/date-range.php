<?php
/**
 * Period filter for a GET filter form: rolling presets (?range=7d|30d|90d|365d|all)
 * plus a hand-picked day range (?from=&to=, both inclusive).
 *
 * Nothing here reaches the query string until the operator asks for it. A date
 * input only gets its `name` once that bound was picked (now or on an earlier
 * request) — until then it merely shows the window the list is using — and a
 * preset travels as its key, not as resolved days. So applying any other filter
 * keeps a rolling window rolling instead of freezing today's dates into the
 * URL. Naming the input on change (not in a submit handler) also covers forms
 * whose selects call form.submit(), which fires no submit event. Picking a day
 * by hand drops the preset.
 *
 * @var array<string,mixed> $filters  reads 'from' / 'to' ('Y-m-d' or null) and 'range'
 * @var string   $range_from   'Y-m-d' shown in the first input
 * @var string   $range_to     'Y-m-d' shown in the second input
 * @var callable(string):string $rangeUrl  URL of this list under the given preset
 * @var string   $rangeDefault    preset the list uses when nothing is picked ('7d' | 'all')
 * @var string   $rangeInputClass optional, default 'input-sm input-mono'
 * @var bool     $rangeAutoSubmit optional, submit the form on change
 * @var bool     $rangeBare       optional, inputs only — no label/field wrapper
 */
$filters = $filters ?? [];
$range_from = (string)($range_from ?? '');
$range_to = (string)($range_to ?? '');
$rangeInputClass = $rangeInputClass ?? 'input-sm input-mono';
$rangeAutoSubmit = $rangeAutoSubmit ?? false;
$rangeBare = $rangeBare ?? false;
$rangeUrl = $rangeUrl ?? null;
$rangePreset = is_string($filters['range'] ?? null) ? $filters['range'] : null;
$rangePicked = !empty($filters['from']) || !empty($filters['to']);
$rangeActive = $rangePreset ?? ($rangePicked ? null : ($rangeDefault ?? null));

$rangeInput = static function (string $bound, string $value, string $label) use ($filters, $rangeInputClass, $rangeAutoSubmit): string {
    return '<input type="date"'
        . (!empty($filters[$bound]) ? ' name="' . $bound . '"' : '')
        . ' value="' . e($value) . '" max="' . e(date('Y-m-d')) . '"'
        . ' aria-label="' . e($label) . '" class="' . e($rangeInputClass) . '"'
        . ' style="flex:1 1 0;min-width:0;width:140px"'
        . ' onchange="this.name=\'' . $bound . '\';var p=this.form.querySelector(\'input[data-range-preset]\');if(p)p.disabled=true'
        . ($rangeAutoSubmit ? ';this.form.submit()' : '') . '">';
};
$rangeInputs = $rangeInput('from', $range_from, t('filter_opt.date_from'))
    . '<span aria-hidden="true" style="color:var(--color-faint)">–</span>'
    . $rangeInput('to', $range_to, t('filter_opt.date_to'))
    // Keeps an active preset across "apply" of the other filters.
    . ($rangePreset !== null ? '<input type="hidden" name="range" value="' . e($rangePreset) . '" data-range-preset>' : '');

$rangeChips = '';
if (is_callable($rangeUrl)) {
    foreach (array_keys(\App\Shared\Time\DateRange::PRESETS) as $key) {
        $on = $key === $rangeActive;
        $rangeChips .= '<a href="' . e($rangeUrl($key)) . '"' . ($on ? ' aria-current="true"' : '')
            . ' style="font-family:var(--font-sans);font-size:0.65rem;font-weight:500;letter-spacing:0.04em;text-transform:uppercase;text-decoration:none;white-space:nowrap;'
            . 'padding:1px 5px;border-radius:3px;color:' . ($on ? 'var(--color-terra-600, var(--color-terra-500));background:rgba(181,79,23,0.10)' : 'var(--color-muted)') . '">'
            . e(t('filter_opt.range_' . $key)) . '</a>';
    }
}
?>
<?php if ($rangeBare): ?>
    <span style="display:inline-flex;gap:4px;align-items:center;flex-wrap:wrap" title="<?= e(t('filter_opt.date_range')) ?>"><?= $rangeInputs ?><span style="display:inline-flex;gap:2px;margin-left:4px"><?= $rangeChips ?></span></span>
<?php else: ?>
    <div class="filter-field">
        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px">
            <span class="filter-label"><?= e(t('filter_opt.date_range')) ?></span>
            <span style="display:inline-flex;gap:2px"><?= $rangeChips ?></span>
        </div>
        <div style="display:flex;gap:4px;align-items:center"><?= $rangeInputs ?></div>
    </div>
<?php endif; ?>
