<?php
/**
 * Copyable test link for a campaign or a flow (see App\Shared\TestLink).
 *
 * @var string|null $testKey      derived key; null when APP_SECRET is unset
 * @var string      $testBase     direct click URL, e.g. https://tds/Ab3xYz
 * @var bool        $testCompact  one-line variant for flow cards
 * @var string      $testHint     explanatory line under the full variant
 */
$testCompact = $testCompact ?? false;
if ($testKey === null) {
    if (!$testCompact) {
        echo '<p style="font-size:0.78rem;color:var(--color-faint);font-family:var(--font-sans)">' . e(t('test_link.disabled')) . '</p>';
    }
    return;
}
$testParam = '_t=' . $testKey;
$testLinkUrl = $testBase . '?' . $testParam . '&_dbg=1';
$copyJs = static fn (string $value): string => 'navigator.clipboard.writeText(' . json_encode($value, JSON_UNESCAPED_SLASHES) . ');'
    . "window.dispatchEvent(new CustomEvent('toast',{detail:{type:'success',msg:" . json_encode(t('test_link.copied'), JSON_UNESCAPED_UNICODE) . '}}))';
?>
<?php if ($testCompact): ?>
    <div style="display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap;font-family:var(--font-sans);font-size:0.78rem">
        <span class="eyebrow" style="font-size:0.65rem;color:var(--color-faint)"><?= e(t('test_link.eyebrow')) ?></span>
        <code style="font-family:var(--font-mono);background:var(--color-stone-100);padding:1px 6px;border-radius:3px;font-size:0.72rem"><?= e($testParam) ?></code>
        <button type="button" class="btn-ghost" style="font-size:0.72rem;padding:1px 6px" onclick="<?= e($copyJs($testLinkUrl)) ?>"><?= e(t('test_link.copy_link')) ?></button>
        <button type="button" class="btn-ghost" style="font-size:0.72rem;padding:1px 6px" onclick="<?= e($copyJs($testParam)) ?>"><?= e(t('test_link.copy_param')) ?></button>
    </div>
<?php else: ?>
    <div style="display:flex;flex-direction:column;gap:8px">
        <div>
            <label class="label-uppercase"><?= e(t('test_link.direct')) ?></label>
            <div class="copyable">
                <input type="text" readonly value="<?= e($testLinkUrl) ?>" onclick="this.select()">
                <button type="button" onclick="<?= e($copyJs($testLinkUrl)) ?>"><?= e(t('campaigns.copy')) ?></button>
            </div>
        </div>
        <div>
            <label class="label-uppercase"><?= e(t('test_link.lander')) ?></label>
            <div class="copyable">
                <input type="text" readonly value="?<?= e($testParam) ?>" onclick="this.select()">
                <button type="button" onclick="<?= e($copyJs('?' . $testParam)) ?>"><?= e(t('campaigns.copy')) ?></button>
            </div>
        </div>
        <p class="form-help" style="margin:0"><?= e($testHint) ?></p>
        <p class="form-help" style="margin:0"><?= e(t('test_link.extras')) ?></p>
    </div>
<?php endif; ?>
