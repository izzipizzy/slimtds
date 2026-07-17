<?php
/** @var ?\App\Admin\Repository\Offer $offer */
/** @var int $flow_refs */
/** @var array<string,string> $errors */
/** @var string $csrf_token */
$action   = $offer === null
    ? url('/admin/offers')
    : url('/admin/offers/' . $offer->id);
$titleKey = $offer === null ? 'offers.create' : 'offers.edit';
?>
<div style="max-width:560px">
    <nav style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans);margin-bottom:20px">
        <a href="<?= e(url('/admin/offers')) ?>" style="color:var(--color-stone-400);text-decoration:none"><?= e(t('offers.title')) ?></a>
        <span style="margin:0 6px;color:var(--color-terra-400)">/</span>
        <span><?= e(t($titleKey)) ?></span>
    </nav>

    <h1 class="page-title-sm"><?= e(t($titleKey)) ?></h1>

    <form method="post" action="<?= e($action) ?>">
        <?= csrf_field($csrf_token) ?>

        <!-- Section: identity -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('offers.section_identity')) ?></span>
            <div style="display:flex;flex-direction:column;gap:18px">
                <div>
                    <label class="label-uppercase" for="name"><?= e(t('offers.name')) ?></label>
                    <input id="name" name="name" class="input" required
                           value="<?= e(old('name', $offer?->name ?? '')) ?>">
                    <?php if (isset($errors['name'])): ?>
                        <p class="form-error"><?= e(t($errors['name'])) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="label-uppercase" for="url"><?= e(t('offers.url')) ?></label>
                    <textarea id="url" name="url" class="input" required rows="1"
                              x-data
                              x-init="const grow = () => { $el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px' }; grow(); $el.addEventListener('input', grow)"
                              style="font-family:var(--font-mono);font-size:0.8rem;resize:none;overflow:hidden;min-height:44px;word-break:break-all"><?= e(old('url', $offer?->url ?? '')) ?></textarea>
                    <p class="form-help"><?= e(t('offers.url_hint')) ?></p>
                    <?php if (isset($errors['url'])): ?>
                        <p class="form-error"><?= e(t($errors['url'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Section: payout -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('offers.section_payout')) ?></span>
            <div class="adapt-stack" style="display:grid;grid-template-columns:1fr 120px;gap:16px">
                <div>
                    <label class="label-uppercase" for="payout_default"><?= e(t('offers.payout_default')) ?></label>
                    <input id="payout_default" name="payout_default" class="input" type="text"
                           inputmode="decimal"
                           style="font-variant-numeric:tabular-nums"
                           value="<?= e(old('payout_default', $offer?->payoutDefault ?? '')) ?>">
                    <?php if (isset($errors['payout_default'])): ?>
                        <p class="form-error"><?= e(t($errors['payout_default'])) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="label-uppercase" for="currency"><?= e(t('offers.currency')) ?></label>
                    <input id="currency" name="currency" class="input" maxlength="3"
                           style="text-transform:uppercase;letter-spacing:0.05em;font-family:var(--font-mono)"
                           value="<?= e(old('currency', $offer?->currency ?? 'USD')) ?>">
                    <?php if (isset($errors['currency'])): ?>
                        <p class="form-error"><?= e(t($errors['currency'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Section: state -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('offers.section_state')) ?></span>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.875rem;font-family:var(--font-sans);color:var(--color-stone-700)">
                <input type="checkbox" id="is_active" name="is_active" value="1"
                       <?= old('is_active', $offer?->isActive ?? true) ? 'checked' : '' ?>>
                <?= e(t('offers.is_active')) ?>
            </label>
        </div>

        <?php if ($offer !== null): ?>
        <!-- Section: postback token -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('offers.postback_token')) ?></span>
            <div>
                <input class="input" readonly style="font-family:var(--font-mono);font-size:0.8rem;color:var(--color-stone-500)"
                       value="<?= e($offer->postbackToken) ?>">
                <p style="margin-top:4px;font-size:0.75rem;font-family:var(--font-mono);color:var(--color-stone-400)"><?= e(t('offers.postback_hint')) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section: outgoing postbacks (S2S) -->
        <div class="form-section">
            <span class="form-section-label"><?= e(t('offers.section_outgoing')) ?></span>
            <div>
                <?php
                    // Build raw textarea value from existing URLs or from _old on validation failure
                    $existingUrls = $offer !== null ? $offer->postbackUrls : [];
                    $oldRaw = old('postback_urls_raw', null);
                    if ($oldRaw !== null) {
                        $textareaValue = $oldRaw;
                    } else {
                        $textareaValue = implode("\n", $existingUrls);
                    }
                ?>
                <label class="label-uppercase" for="postback_urls_raw"><?= e(t('offers.postback_urls_label')) ?></label>
                <textarea id="postback_urls_raw" name="postback_urls_raw" rows="4"
                          class="input"
                          style="font-family:var(--font-mono);font-size:0.78rem;resize:vertical;min-height:80px"
                          placeholder="<?= e(t('offers.postback_urls_ph')) ?>"><?= e($textareaValue) ?></textarea>
                <?php $pbMacroStyle = 'font-family:var(--font-mono);font-size:0.78rem'; ?>
                <p style="margin-top:4px;font-size:0.73rem;color:var(--color-stone-400);font-family:var(--font-sans)"><?= t('offers.postback_urls_help', ['macros' =>
                    '<code style="' . $pbMacroStyle . '">{click_id}</code> '
                    . '<code style="' . $pbMacroStyle . '">{payout}</code> '
                    . '<code style="' . $pbMacroStyle . '">{status}</code> '
                    . '<code style="' . $pbMacroStyle . '">{external_id}</code> '
                    . '<code style="' . $pbMacroStyle . '">{currency}</code>',
                ]) ?></p>
                <?php if (isset($errors['postback_urls'])): ?>
                    <p class="form-error"><?= e(t($errors['postback_urls'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions -->
        <div style="display:flex;gap:10px;margin-top:28px;padding-top:20px;border-top:1px solid var(--color-stone-100)">
            <button type="submit" class="btn"><?= e(t('campaigns.save')) ?></button>
            <a href="<?= e(url('/admin/offers')) ?>" class="btn-secondary"><?= e(t('form.cancel')) ?></a>
        </div>
    </form>

    <?php if ($offer !== null): ?>
    <div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--color-stone-100);display:flex;justify-content:space-between;align-items:center">
        <form method="post" action="<?= e(url('/admin/offers/' . $offer->id . '/rotate-token')) ?>">
            <?= csrf_field($csrf_token) ?>
            <button type="submit" class="btn-ghost" style="font-size:0.75rem;color:var(--color-stone-400)"><?= e(t('offers.rotate_token')) ?></button>
        </form>
        <span style="font-size:0.75rem;color:var(--color-stone-400);font-family:var(--font-sans)">
            <?= e(tn('offers.usage_count', (int)($flow_refs ?? 0))) ?>
        </span>
    </div>
    <?php endif; ?>
</div>
