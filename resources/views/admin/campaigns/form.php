<?php
/** @var ?\App\Admin\Repository\Campaign $campaign */
/** @var list<\App\Admin\Repository\Offer> $offers */
/** @var list<\App\Admin\Repository\Flow> $flows */
/** @var string $app_url */
/** @var array<string,string> $errors */
/** @var string $csrf_token */
$action   = $campaign === null ? url('/admin/campaigns') : url('/admin/campaigns/' . $campaign->id);
$titleKey = $campaign === null ? 'campaigns.create' : 'campaigns.edit';

$liveUrl = $campaign === null ? null : $app_url . '/' . $campaign->slug;
$pixelSnippet = $campaign === null ? null : '<script async src="' . $app_url . '/p.js?c=' . $campaign->slug . '"></script>';
?>

<?php if ($campaign !== null): ?>
<!-- Header: name + slug + actions -->
<div style="margin-bottom:32px">
    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:24px;flex-wrap:wrap">
        <div>
            <h1 class="page-title">
                <?= e($campaign->name) ?>
            </h1>
            <div style="display:flex;align-items:center;gap:8px;font-family:var(--font-mono);font-size:0.8rem;color:var(--color-stone-500)">
                <span><?= e($campaign->slug) ?></span>
                <span style="color:var(--color-stone-300)">·</span>
                <?php if ($campaign->isActive): ?>
                    <span style="color:var(--color-success)">● active</span>
                <?php else: ?>
                    <span style="color:var(--color-stone-400)">○ inactive</span>
                <?php endif; ?>
                <span style="color:var(--color-stone-300)">·</span>
                <span><?= e($campaign->createdAt->format('Y-m-d')) ?></span>
            </div>
        </div>
        <div style="display:flex;gap:8px">
            <a href="<?= e($liveUrl) ?>" target="_blank" rel="noopener"
               class="btn-secondary" style="font-size:0.8rem">↗ <?= e(t('campaigns.open')) ?></a>
            <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/stats')) ?>"
               class="btn-secondary" style="font-size:0.8rem"><?= e(t('campaigns.stats')) ?> →</a>
            <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/delete')) ?>"
               class="btn-ghost" style="font-size:0.8rem;color:var(--color-danger)"><?= e(t('campaigns.delete_short')) ?></a>
        </div>
    </div>
</div>

<!-- Live URL panel -->
<div style="margin-bottom:32px;padding:16px;border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-stone-50)">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="flex:1;min-width:240px">
            <div style="font-size:0.7rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--color-stone-500);font-family:var(--font-sans);margin-bottom:6px"><?= e(t('campaigns.traffic_url')) ?></div>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="text" readonly value="<?= e($liveUrl) ?>"
                       style="flex:1;padding:8px 12px;border:1px solid var(--color-stone-200);border-radius:4px;background:var(--color-surface);font-family:var(--font-mono);font-size:0.85rem;color:var(--color-stone-900)"
                       onclick="this.select()">
                <button type="button"
                        onclick="navigator.clipboard.writeText('<?= e($liveUrl) ?>'); this.textContent=<?= htmlspecialchars(json_encode(t('campaigns.copied'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>; setTimeout(()=>this.textContent=<?= htmlspecialchars(json_encode(t('campaigns.copy'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,1500)"
                        class="btn-secondary" style="font-size:0.8rem"><?= e(t('campaigns.copy')) ?></button>
            </div>
            <div style="font-size:0.75rem;color:var(--color-stone-400);font-family:var(--font-sans);margin-top:6px">
                <?= e(t('campaigns.traffic_url_hint')) ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// Reusable settings-form snippet (create-mode + tab content)
$renderSettings = function () use ($campaign, $action, $errors, $csrf_token, $titleKey) { ?>
<div style="max-width:560px">
    <?php if ($campaign === null): ?>
        <h2 class="section-title" style="margin-bottom:16px"><?= e(t($titleKey)) ?></h2>
    <?php endif; ?>

    <form method="post" action="<?= e($action) ?>">
        <?= csrf_field($csrf_token) ?>

        <div class="form-section">
            <span class="form-section-label"><?= e(t('campaigns.section_identity')) ?></span>
            <div class="form-row">
                <label class="label-uppercase" for="name"><?= e(t('campaigns.name')) ?></label>
                <input id="name" name="name" class="input" required value="<?= e(old('name', $campaign?->name ?? '')) ?>">
                <?php if (isset($errors['name'])): ?><p class="form-error"><?= e(t($errors['name'])) ?></p><?php endif; ?>
            </div>
            <div class="form-row">
                <label class="label-uppercase" for="slug"><?= e(t('campaigns.slug')) ?></label>
                <input id="slug" name="slug" class="input input-mono"
                       value="<?= e(old('slug', $campaign?->slug ?? '')) ?>"
                       placeholder="<?= e(t('campaigns.slug_hint')) ?>">
                <p class="form-help"><?= e(t('campaigns.slug_hint')) ?></p>
                <?php if (isset($errors['slug'])): ?><p class="form-error"><?= e(t($errors['slug'])) ?></p><?php endif; ?>
            </div>
            <div class="form-row">
                <label class="label-uppercase" for="notes"><?= e(t('campaigns.notes')) ?></label>
                <textarea id="notes" name="notes" rows="3" class="input"><?= e(old('notes', $campaign?->notes ?? '')) ?></textarea>
            </div>
        </div>

        <div class="form-section">
            <span class="form-section-label"><?= e(t('campaigns.section_routing')) ?></span>
            <div class="form-row">
                <label class="label-uppercase" for="trash_mode"><?= e(t('campaigns.trash_mode')) ?></label>
                <select id="trash_mode" name="trash_mode" class="input" style="max-width:340px">
                    <?php for ($mode = 0; $mode <= 7; $mode++): ?>
                        <option value="<?= $mode ?>" <?= (int)old('trash_mode', $campaign?->trashMode ?? 0) === $mode ? 'selected' : '' ?>>
                            <?= e(t('campaigns.trash_mode_' . $mode)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-row" x-data>
                <label class="label-uppercase" for="trash_url"><?= e(t('campaigns.trash_url')) ?></label>
                <input id="trash_url" name="trash_url" class="input input-mono" type="text"
                       placeholder="https://…  ·  {offer:name}  ·  {spin:a|b}"
                       value="<?= e(old('trash_url', $campaign?->trashUrl ?? '')) ?>">
                <?php if (!empty($offers)): ?>
                    <select class="input-sm" style="margin-top:6px;max-width:340px"
                            @change="if ($el.value) { document.getElementById('trash_url').value = '{offer:' + $el.value + '}'; $el.value = ''; }">
                        <option value=""><?= e(t('campaigns.trash_insert_offer')) ?></option>
                        <?php foreach ($offers as $o): ?>
                            <option value="<?= e($o->name) ?>"><?= e($o->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if (isset($errors['trash_url'])): ?><p class="form-error"><?= e(t($errors['trash_url'])) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="form-section">
            <span class="form-section-label"><?= e(t('campaigns.section_state')) ?></span>
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:0.875rem;font-family:var(--font-sans);color:var(--color-stone-700)">
                <input type="checkbox" id="is_active" name="is_active" value="1" <?= old('is_active', $campaign?->isActive ?? true) ? 'checked' : '' ?>>
                <?= e(t('campaigns.is_active')) ?>
            </label>
            <!-- hidden ensures the key is always submitted (unchecked → '0') -->
            <input type="hidden" name="sticky_offer" value="0">
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-size:0.875rem;font-family:var(--font-sans);color:var(--color-stone-700);margin-top:8px">
                <input type="checkbox" id="sticky_offer" name="sticky_offer" value="1" <?= old('sticky_offer', $campaign?->stickyOffer ?? true) ? 'checked' : '' ?>>
                <?= e(t('campaigns.sticky_offer')) ?>
            </label>
            <p style="font-size:0.78rem;color:var(--color-stone-500);margin:4px 0 0;font-family:var(--font-sans)"><?= e(t('campaigns.sticky_offer_hint')) ?></p>
        </div>

        <div style="display:flex;gap:10px;margin-top:24px;padding-top:18px;border-top:1px solid var(--color-border-soft)">
            <button type="submit" class="btn"><?= e(t('campaigns.save')) ?></button>
            <a href="<?= e(url('/admin/campaigns')) ?>" class="btn-secondary"><?= e(t('form.cancel')) ?></a>
        </div>
    </form>
</div>
<?php }; ?>

<?php if ($campaign === null): $renderSettings(); ?>

<?php else: ?>

<!-- ── Tabs (edit-mode workspace) ───────────────────────────────────── -->
<div x-data="{ tab: (location.hash ? location.hash.slice(1) : 'settings') }"
     x-init="$watch('tab', v => history.replaceState(null, '', '#' + v))">
    <nav class="tabs" role="tablist">
        <button class="tab" role="tab" :class="{ 'is-active': tab === 'settings' }" @click="tab = 'settings'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9 1.7 1.7 0 0 0 4.3 7.2l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>
            <?= e(t('campaigns.tab_settings')) ?>
        </button>
        <button class="tab" role="tab" :class="{ 'is-active': tab === 'flows' }" @click="tab = 'flows'">
            <?= e(t('flows.title')) ?> <span class="tab-count"><?= count($flows) ?></span>
        </button>
        <button class="tab" role="tab" :class="{ 'is-active': tab === 'pixel' }" @click="tab = 'pixel'"><?= e(t('campaigns.tab_pixel')) ?></button>
        <button class="tab" role="tab" :class="{ 'is-active': tab === 'postback' }" @click="tab = 'postback'"><?= e(t('campaigns.tab_postback')) ?></button>
        <button class="tab" role="tab" :class="{ 'is-active': tab === 'diagram' }" @click="tab = 'diagram'; $nextTick(() => window.renderCampaignDiagram && window.renderCampaignDiagram())">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="5" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/><rect x="14" y="16" width="7" height="5" rx="1"/><path d="M6.5 8v8M17.5 8v8M10 5h4M10 19h4"/></svg>
            <?= e(t('campaigns.diagram')) ?>
        </button>
        <button class="tab" role="tab" :class="{ 'is-active': tab === 'danger' }" @click="tab = 'danger'" style="margin-left:auto;color:var(--color-danger)"><?= e(t('campaigns.tab_danger')) ?></button>
    </nav>

    <!-- ===== Settings tab ===== -->
    <section x-show="tab === 'settings'" class="anim-fade-in" role="tabpanel">
        <?php $renderSettings(); ?>
    </section>

    <!-- ===== Flows tab ===== -->
    <section x-show="tab === 'flows'" x-cloak class="anim-fade-in" role="tabpanel">
    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:8px;gap:16px;flex-wrap:wrap">
        <div>
            <h2 class="section-title"><?= e(t('flows.title')) ?></h2>
        </div>
        <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/new')) ?>" class="btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            <?= e(t('flows.create')) ?>
        </a>
    </div>
    <p style="font-size:0.82rem;color:var(--color-muted);font-family:var(--font-sans);margin-bottom:16px;line-height:1.5;max-width:60ch">
        <?= e(t('campaigns.flows_intro')) ?>
    </p>

    <?php if (empty($flows)): ?>
        <?php
        $title = t('flows.count.zero');
        $text = t('flows.empty_hint');
        $iconBody = '<circle cx="6" cy="6" r="2.2"/><circle cx="18" cy="12" r="2.2"/><circle cx="6" cy="18" r="2.2"/><path d="M8 7l8 4M8 17l8-4"/>';
        $ctaLabel = t('flows.create');
        $ctaHref = url('/admin/campaigns/' . $campaign->id . '/flows/new');
        $ctaIcon = '<path d="M12 5v14M5 12h14"/>';
        require __DIR__ . '/../../_partials/empty-state.php';
        ?>
    <?php else: ?>
        <?php
        // Build offer-id → Offer DTO map so each flow can resolve its target offers
        $offerMap = [];
        foreach ($offers as $o) { $offerMap[$o->id] = $o; }
        ?>
        <div style="display:flex;flex-direction:column;gap:10px">
            <?php foreach ($flows as $i => $f): $isFirst = $i === 0; $isLast = $i === count($flows) - 1; ?>
                <div style="border:1px solid var(--color-border);border-radius:8px;background:var(--color-surface);padding:14px 16px;display:grid;grid-template-columns:48px 1fr auto;gap:14px;align-items:start;transition:border-color 150ms ease"
                     onmouseover="this.style.borderColor='var(--color-terra-300)'"
                     onmouseout="this.style.borderColor='var(--color-border)'">
                    <!-- Position + reorder -->
                    <div style="display:flex;flex-direction:column;align-items:center;gap:2px;padding-top:2px">
                        <form method="post" action="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/' . $f->id . '/move?dir=up')) ?>" style="margin:0">
                            <?= csrf_field($csrf_token) ?>
                            <button type="submit" class="btn-ghost" <?= $isFirst ? 'disabled' : '' ?> aria-label="<?= e(t('flows.move_up')) ?>" title="<?= e(t('flows.move_up')) ?>" style="padding:0 6px;line-height:1;font-size:0.65rem;color:<?= $isFirst ? 'var(--color-stone-200)' : 'var(--color-stone-500)' ?>;background:transparent;border:0;cursor:<?= $isFirst ? 'default' : 'pointer' ?>">▲</button>
                        </form>
                        <div style="font-family:var(--font-mono);font-size:0.78rem;color:var(--color-faintest);line-height:1">#<?= (int)$f->position ?></div>
                        <form method="post" action="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/' . $f->id . '/move?dir=down')) ?>" style="margin:0">
                            <?= csrf_field($csrf_token) ?>
                            <button type="submit" class="btn-ghost" <?= $isLast ? 'disabled' : '' ?> aria-label="<?= e(t('flows.move_down')) ?>" title="<?= e(t('flows.move_down')) ?>" style="padding:0 6px;line-height:1;font-size:0.65rem;color:<?= $isLast ? 'var(--color-stone-200)' : 'var(--color-stone-500)' ?>;background:transparent;border:0;cursor:<?= $isLast ? 'default' : 'pointer' ?>">▼</button>
                        </form>
                    </div>

                    <!-- Body -->
                    <div style="min-width:0">
                        <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:4px;flex-wrap:wrap">
                            <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/' . $f->id . '/edit')) ?>"
                               style="font-family:var(--font-display);font-size:1rem;font-weight:600;color:var(--color-text);text-decoration:none;letter-spacing:-0.01em"><?= e($f->name) ?></a>
                            <span class="badge <?= $f->isActive ? 'badge-success' : 'badge-ghost' ?>"><?= e($f->isActive ? t('flows.status_active') : t('flows.status_inactive')) ?></span>
                            <span class="badge badge-neutral"><?= e(t('schemas.' . $f->schemaId)) ?></span>
                        </div>

                        <!-- Filters preview -->
                        <?php
                        $filters = $f->filters ?? [];
                        $totalConditions = 0;
                        foreach ($filters as $group) { $totalConditions += count($group); }
                        ?>
                        <div style="display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap;font-family:var(--font-sans);font-size:0.78rem;color:var(--color-muted)">
                            <span class="eyebrow" style="font-size:0.65rem"><?= e(t('campaigns.filters_eyebrow')) ?></span>
                            <?php if ($totalConditions === 0): ?>
                                <span style="color:var(--color-faint)"><?= e(t('campaigns.any_traffic')) ?></span>
                            <?php else: ?>
                                <?php
                                $previewBits = [];
                                foreach ($filters as $gi => $group) {
                                    foreach ($group as $cond) {
                                        $field = $cond['field'] ?? '?';
                                        $op = $cond['op'] ?? '=';
                                        $val = $cond['value'] ?? '';
                                        if (is_array($val)) $val = implode(',', $val);
                                        $previewBits[] = "<code style=\"font-family:var(--font-mono);background:var(--color-stone-100);padding:1px 6px;border-radius:3px;font-size:0.72rem\">" . e($field) . " " . e($op) . " " . e((string)$val) . "</code>";
                                    }
                                    if ($gi < count($filters) - 1) $previewBits[] = '<span style="color:var(--color-faint);font-family:var(--font-mono);font-size:0.7rem">OR</span>';
                                }
                                echo implode(' ', $previewBits);
                                ?>
                            <?php endif; ?>
                        </div>

                        <!-- Target offers — the flow→offer link -->
                        <div style="display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap;font-family:var(--font-sans);font-size:0.78rem">
                            <span class="eyebrow" style="font-size:0.65rem;color:var(--color-faint)">→ offers</span>
                            <?php if ($f->targetType !== 'offers' || empty($f->targetOffers)): ?>
                                <span style="color:var(--color-faint)"><?= e(t('campaigns.no_target_offers')) ?></span>
                            <?php else: ?>
                                <?php foreach ($f->targetOffers as $to): ?>
                                    <?php $oid = (string)($to['offer_id'] ?? ''); $weight = (int)($to['weight'] ?? 0); $offer = $offerMap[$oid] ?? null; ?>
                                    <?php if ($offer): ?>
                                        <a href="<?= e(url('/admin/offers/' . $offer->id . '/edit')) ?>"
                                           style="display:inline-flex;align-items:center;gap:6px;padding:2px 9px;border-radius:99px;background:var(--color-terra-50);border:1px solid var(--color-terra-100);color:var(--color-terra-600);text-decoration:none;font-size:0.75rem">
                                            <?= e($offer->name) ?>
                                            <span style="font-family:var(--font-mono);color:var(--color-terra-500);font-variant-numeric:tabular-nums"><?= $weight ?>%</span>
                                        </a>
                                    <?php else: ?>
                                        <span style="display:inline-flex;align-items:center;gap:6px;padding:2px 9px;border-radius:99px;background:var(--color-stone-100);color:var(--color-faint);font-size:0.75rem;font-family:var(--font-mono)">
                                            ?? <?= $weight ?>%
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex;gap:6px;align-items:center;white-space:nowrap">
                        <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/flows/' . $f->id . '/edit')) ?>" class="btn-ghost" style="font-size:0.75rem"><?= e(t('campaigns.flow_edit')) ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </section>

    <!-- ===== Pixel tab ===== -->
    <section x-show="tab === 'pixel'" x-cloak class="anim-fade-in" role="tabpanel" style="max-width:760px">
    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px">
        <h2 class="section-title"><?= e(t('campaigns.tab_pixel')) ?></h2>
        <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/pixel')) ?>" class="btn-secondary" style="font-size:0.8rem"><?= e(t('campaigns.pixel_full_page')) ?></a>
    </div>
    <?php $codeStyle = 'background:var(--color-stone-100);padding:1px 5px;border-radius:2px;font-family:var(--font-mono);font-size:0.78rem'; ?>
    <p style="font-size:0.85rem;color:var(--color-muted);font-family:var(--font-sans);margin-bottom:16px;line-height:1.5">
        <?= t('campaigns.pixel_install_hint', [
            'head'      => '<code style="' . $codeStyle . '">&lt;head&gt;</code>',
            'bodyclose' => '<code style="' . $codeStyle . '">&lt;/body&gt;</code>',
        ]) ?>
    </p>
    <div style="display:flex;gap:8px;align-items:flex-start">
        <pre style="flex:1;margin:0;padding:14px 16px;border:1px solid var(--color-border);border-radius:6px;background:var(--color-surface-2);font-family:var(--font-mono);font-size:0.8rem;color:var(--color-text);overflow-x:auto;white-space:pre"><?= e($pixelSnippet) ?></pre>
        <button type="button"
                onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($pixelSnippet, JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>); window.dispatchEvent(new CustomEvent('toast',{detail:{type:'success',msg:<?= htmlspecialchars(json_encode(t('campaigns.pixel_snippet_copied'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>}}))"
                class="btn-secondary" style="font-size:0.8rem;flex-shrink:0"><?= e(t('campaigns.copy')) ?></button>
    </div>
    <p style="font-size:0.78rem;color:var(--color-faint);font-family:var(--font-sans);margin-top:10px">
        <?= e(t('campaigns.pixel_custom_events')) ?> <code style="background:var(--color-stone-100);padding:1px 5px;border-radius:2px;font-family:var(--font-mono);font-size:0.78rem">window.slimTDS.track('purchase', {amount: 99})</code>
    </p>
    </section>

    <!-- ===== Postback tab ===== -->
    <section x-show="tab === 'postback'" x-cloak class="anim-fade-in" role="tabpanel" style="max-width:880px">
    <h2 class="section-title"><?= e(t('campaigns.tab_postback')) ?></h2>

    <?php if ($campaign !== null && $campaign->postbackToken !== ''): ?>
        <?php $campPbUrl = $app_url . '/postback?subid={click_id}&token=' . $campaign->postbackToken . '&payout=&status=approved'; ?>
        <div style="margin:6px 0 18px;padding:14px 16px;border:1px solid var(--color-terra-300);border-radius:8px;background:#fff8f1">
            <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:6px">
                <span style="font-family:var(--font-display);font-size:0.95rem;font-weight:600;color:var(--color-text)"><?= e(t('campaigns.postback_campaign_url')) ?></span>
                <span class="badge badge-terra">catch-all</span>
            </div>
            <p style="font-size:0.78rem;color:var(--color-muted);font-family:var(--font-sans);margin:0 0 10px;line-height:1.5">
                <?= t('campaigns.postback_campaign_hint_1', [
                    'strong_one' => '<strong>' . e(t('campaigns.postback_one')) . '</strong>',
                    'offer_id'   => '<code style="font-family:var(--font-mono);font-size:0.78rem;background:var(--color-stone-100);padding:1px 5px;border-radius:2px">offer_id</code>',
                ]) ?>
                <?= t('campaigns.postback_campaign_hint_2', [
                    'strong_diff' => '<strong>' . e(t('campaigns.postback_diff')) . '</strong>',
                ]) ?>
            </p>
            <div class="copyable">
                <input type="text" readonly value="<?= e($campPbUrl) ?>" onclick="this.select()">
                <button type="button"
                        onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($campPbUrl, JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>); window.dispatchEvent(new CustomEvent('toast',{detail:{type:'success',msg:<?= htmlspecialchars(json_encode(t('campaigns.campaign_postback_copied'), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>}}))"><?= e(t('campaigns.copy')) ?></button>
            </div>
        </div>

        <h3 style="font-family:var(--font-display);font-size:0.92rem;font-weight:600;margin:18px 0 6px;color:var(--color-stone-600);letter-spacing:-0.01em"><?= e(t('campaigns.postback_per_offer')) ?></h3>
    <?php endif; ?>

    <p style="font-size:0.85rem;color:var(--color-muted);font-family:var(--font-sans);margin:6px 0 16px;line-height:1.5">
        <?= t('campaigns.postback_per_offer_hint', [
            'click_id_code' => '<code style="background:var(--color-stone-100);padding:1px 5px;border-radius:2px;font-family:var(--font-mono);font-size:0.78rem">{click_id}</code>',
        ]) ?>
    </p>
    <?php if (empty($offers)): ?>
        <?php
        $title = t('campaigns.postback_empty_title');
        $text = t('campaigns.postback_empty_text');
        $iconBody = '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>';
        $ctaLabel = t('offers.create');
        $ctaHref = url('/admin/offers/new');
        $ctaIcon = '<path d="M12 5v14M5 12h14"/>';
        require __DIR__ . '/../../_partials/empty-state.php';
        ?>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px">
            <?php foreach ($offers as $o): ?>
                <?php $pbUrl = $app_url . '/postback?subid={click_id}&token=' . $o->postbackToken . '&payout=&status=approved'; ?>
                <div style="display:flex;align-items:center;gap:10px">
                    <span style="min-width:140px;font-size:0.875rem;color:var(--color-text);font-family:var(--font-sans);font-weight:500"><?= e($o->name) ?></span>
                    <div class="copyable" style="flex:1">
                        <input type="text" readonly value="<?= e($pbUrl) ?>" onclick="this.select()">
                        <button type="button"
                                onclick="navigator.clipboard.writeText(<?= htmlspecialchars(json_encode($pbUrl, JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>); window.dispatchEvent(new CustomEvent('toast',{detail:{type:'success',msg:<?= htmlspecialchars(json_encode(t('campaigns.postback_copied', ['name' => $o->name]), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>}}))"><?= e(t('campaigns.copy')) ?></button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    </section>

    <!-- ===== Diagram tab ===== -->
    <section x-show="tab === 'diagram'" x-cloak class="anim-fade-in" role="tabpanel"
             x-init="$watch('tab', v => v === 'diagram' && $nextTick(() => window.renderCampaignDiagram && window.renderCampaignDiagram()))">
        <h2 class="section-title"><?= e(t('campaigns.diagram')) ?></h2>
        <p style="font-size:0.85rem;color:var(--color-muted);font-family:var(--font-sans);margin-bottom:18px">
            <?= e(t('campaigns.diagram_hint')) ?>
        </p>
        <?php
        $opSym = ['eq' => '=', 'neq' => '≠', 'in' => 'in', 'not_in' => '∉', 'gt' => '>', 'lt' => '<', 'between' => '↔', 'regex' => '~', 'exists' => '?'];

        $offerLookup = [];
        foreach ($offers as $o) { $offerLookup[$o->id] = $o; }

        // Trash card metadata
        $trashLabels = [
            0 => 'Blank 200',
            1 => '302 Redirect',
            2 => 'HTTP 403',
            3 => 'HTTP 404',
            4 => '301 Redirect',
            5 => '307 Redirect',
            6 => 'Meta Refresh',
            7 => 'JS Redirect',
        ];
        $trashLabel  = $trashLabels[$campaign->trashMode] ?? 'trash';
        $trashUrl    = trim((string)($campaign->trashUrl ?? ''));
        $trashHasUrl = in_array($campaign->trashMode, [1, 4, 5, 6, 7], true) && $trashUrl !== '';
        ?>

        <style>
            .diag-canvas {
                position: relative;
                background: var(--color-surface);
                border: 1px solid var(--color-border);
                border-radius: 10px;
                padding: 28px 32px;
                overflow: auto;
                font-family: ui-sans-serif, system-ui, sans-serif;
            }
            .diag-grid {
                position: relative;
                display: grid;
                grid-template-columns: 280px 320px 1fr;
                column-gap: 84px;
                row-gap: 18px;
                align-items: start;
                z-index: 2;
            }
            .diag-col-eyebrow {
                font-family: ui-monospace, SFMono-Regular, monospace;
                font-size: 0.68rem;
                letter-spacing: 0.16em;
                text-transform: uppercase;
                color: #a8a399;
                margin-bottom: 6px;
                padding: 0 4px;
            }
            .diag-col {
                display: flex;
                flex-direction: column;
                gap: 16px;
                min-width: 0;
            }
            .diag-col-campaign {
                position: sticky;
                top: 0;
                align-self: start;
            }
            .diag-card {
                border: 1px solid var(--color-border);
                border-radius: 8px;
                background: #ffffff;
                padding: 12px 14px;
                transition: border-color 150ms ease, box-shadow 150ms ease, transform 150ms ease;
                box-shadow: 0 1px 0 rgba(26, 25, 23, 0.02);
            }
            .diag-card:hover {
                border-color: var(--color-terra-300);
                box-shadow: 0 2px 8px rgba(217, 113, 62, 0.08);
            }
            .diag-card-head {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 6px;
            }
            .diag-card-icon {
                width: 22px; height: 22px;
                display: inline-flex; align-items: center; justify-content: center;
                border-radius: 5px;
                background: #fff8f1;
                color: #b54f17;
                flex: 0 0 auto;
            }
            .diag-card-title {
                font-family: var(--font-display, 'Bricolage Grotesque'), system-ui, sans-serif;
                font-weight: 600;
                font-size: 0.95rem;
                color: #1a1917;
                letter-spacing: -0.01em;
                line-height: 1.2;
                flex: 1;
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .diag-card-sub {
                font-family: ui-monospace, SFMono-Regular, monospace;
                font-size: 0.72rem;
                color: #a8a399;
                margin-top: -2px;
            }

            /* Campaign card — accent left bar */
            .diag-card-campaign {
                border-color: #d9713e;
                box-shadow: inset 3px 0 0 #d9713e, 0 1px 0 rgba(26, 25, 23, 0.02);
            }
            .diag-card-campaign .diag-card-icon { background: #1a1917; color: #fafaf7; }
            .diag-card-campaign .diag-card-title { font-size: 1.05rem; }
            .diag-card-meta {
                display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; align-items: center;
            }
            .diag-token {
                font-family: ui-monospace, monospace;
                font-size: 0.7rem;
                color: #6b6457;
                background: #efece5;
                padding: 4px 8px;
                border-radius: 4px;
                word-break: break-all;
                margin-top: 8px;
                line-height: 1.5;
            }

            /* Flow card */
            .diag-card-flow.is-off { opacity: 0.55; border-style: dashed; }
            .diag-card-flow .diag-card-icon { background: #fff8f1; color: #b54f17; }
            .diag-card-flow.is-off .diag-card-icon { background: #efece5; color: #a8a399; }
            .diag-prio {
                display: inline-flex; align-items: center; gap: 3px;
                font-family: ui-monospace, monospace; font-size: 0.7rem; font-weight: 600;
                background: #1a1917; color: #fafaf7;
                padding: 2px 7px; border-radius: 4px;
                letter-spacing: 0.02em;
            }
            .diag-prio::before {
                content: '!'; display: inline-block; transform: scaleY(0.85);
                font-weight: 800; color: #fafaf7; opacity: 0.9;
            }
            .diag-prio.diag-prio-trash { background: #b91c1c; }
            .diag-prio.diag-prio-trash::before { content: '⌫'; }
            .diag-card-bar {
                display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; align-items: center;
            }
            .diag-pill {
                display: inline-flex; align-items: center; gap: 4px;
                font-family: ui-sans-serif, system-ui, sans-serif;
                font-size: 0.72rem; font-weight: 500;
                padding: 2px 9px; border-radius: 999px;
                background: #efece5; color: #6b6457;
                border: 1px solid transparent;
                line-height: 1.5;
            }
            .diag-pill-success { background: #ecf7e9; color: #2c6e3a; }
            .diag-pill-mono    { font-family: ui-monospace, monospace; font-size: 0.7rem; }
            .diag-pill-schema  { background: #fff8f1; color: #b54f17; }
            .diag-pill-weight  { background: #1a1917; color: #fafaf7; font-family: ui-monospace, monospace; font-size: 0.7rem; }

            .diag-filters {
                margin-top: 10px; padding-top: 10px;
                border-top: 1px dashed #efece5;
            }
            .diag-filters-row {
                display: flex; flex-wrap: wrap; gap: 4px; align-items: center;
                margin-top: 4px;
            }
            .diag-filters-eyebrow {
                font-family: ui-monospace, monospace; font-size: 0.62rem;
                letter-spacing: 0.14em; text-transform: uppercase; color: #a8a399;
            }
            .diag-cond {
                display: inline-flex; align-items: center; gap: 4px;
                font-family: ui-monospace, monospace; font-size: 0.72rem;
                background: #fafaf7; color: #1a1917;
                border: 1px solid #efece5;
                padding: 1px 7px; border-radius: 4px;
                line-height: 1.5;
            }
            .diag-cond-field { color: #6b6457; }
            .diag-cond-op { color: #d9713e; font-weight: 600; padding: 0 2px; }
            .diag-cond-val { color: #1a1917; font-weight: 500; }
            .diag-cond-or  {
                font-family: ui-monospace, monospace; font-size: 0.65rem;
                color: #b54f17; font-weight: 700; letter-spacing: 0.08em;
                padding: 0 2px;
            }
            .diag-filters-empty {
                font-family: ui-monospace, monospace; font-size: 0.72rem;
                color: #a8a399; font-style: italic;
            }

            /* Offer group container — multiple offer cards stacked */
            .diag-offer-group {
                display: flex; flex-direction: column; gap: 10px;
            }
            .diag-card-offer {
                padding: 10px 14px;
            }
            .diag-card-offer .diag-card-icon { background: #fef3eb; color: #b54f17; }
            .diag-offer-url {
                font-family: ui-monospace, monospace;
                font-size: 0.7rem;
                color: #6b6457;
                margin-top: 2px;
                overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
                max-width: 320px;
                background: #fafaf7;
                padding: 3px 7px;
                border-radius: 3px;
                border: 1px solid #efece5;
            }
            .diag-weight {
                display: flex; align-items: center; gap: 8px; margin-top: 8px;
            }
            .diag-weight-bar {
                flex: 1; height: 5px; background: #efece5; border-radius: 3px; overflow: hidden;
            }
            .diag-weight-fill {
                height: 100%;
                background: linear-gradient(90deg, #d9713e, #b54f17);
                border-radius: 3px;
                transition: width 200ms ease;
            }
            .diag-weight-num {
                font-family: ui-monospace, monospace;
                font-variant-numeric: tabular-nums;
                font-size: 0.72rem;
                font-weight: 600;
                color: #b54f17;
                min-width: 38px;
                text-align: right;
            }

            /* Trash card */
            .diag-card-trash {
                border-color: #fecaca;
                background: #fef2f2;
            }
            .diag-card-trash .diag-card-icon { background: #fee2e2; color: #b91c1c; }
            .diag-card-trash .diag-card-title { color: #7f1d1d; }
            .diag-card-trash .diag-offer-url { background: #fef2f2; border-color: #fecaca; color: #7f1d1d; }

            /* "no target" placeholder */
            .diag-no-target {
                font-family: ui-monospace, monospace;
                font-size: 0.78rem;
                color: #a8a399;
                font-style: italic;
                background: #fafaf7;
                border: 1px dashed #cfcac0;
                padding: 14px;
                border-radius: 8px;
                text-align: center;
            }

            /* SVG overlay for connections — sits behind cards but on top of bg */
            .diag-svg {
                position: absolute;
                top: 0; left: 0;
                width: 100%; height: 100%;
                pointer-events: none;
                z-index: 1;
                overflow: visible;
            }
            .diag-edge {
                fill: none;
                stroke: #cfcac0;
                stroke-width: 1.5;
                opacity: 0.85;
            }
            .diag-edge-flow.is-active { stroke: #d9713e; opacity: 1; }
            .diag-edge-flow.is-off { stroke: #cfcac0; stroke-dasharray: 4 4; }
            .diag-edge-trash { stroke: #b91c1c; stroke-dasharray: 5 4; opacity: 0.65; }
            .diag-edge-offer { stroke: #b54f17; stroke-width: 1.3; opacity: 0.7; }
            .diag-edge-no-target { stroke: #cfcac0; stroke-dasharray: 3 3; opacity: 0.5; }

            /* Edge label badge */
            .diag-edge-label {
                position: absolute;
                z-index: 3;
                transform: translate(-50%, -50%);
                pointer-events: none;
                font-family: ui-monospace, monospace;
                font-size: 0.66rem;
                font-weight: 600;
                font-variant-numeric: tabular-nums;
                background: #1a1917;
                color: #fafaf7;
                padding: 1px 7px;
                border-radius: 999px;
                white-space: nowrap;
                box-shadow: 0 1px 3px rgba(26,25,23,0.15);
            }
            .diag-edge-label.is-trash { background: #b91c1c; }
            .diag-edge-label.is-offer { background: #b54f17; }
            .diag-edge-label.is-off   { background: #cfcac0; color: #6b6457; }
        </style>

        <div id="diagCanvas" class="diag-canvas">
            <div class="diag-grid">
                <!-- ── Column 1: Campaign ── -->
                <div class="diag-col diag-col-campaign">
                    <div class="diag-col-eyebrow"><?= e(t('campaigns.diagram_col_campaign')) ?></div>
                    <div id="dg-campaign" class="diag-card diag-card-campaign">
                        <div class="diag-card-head">
                            <span class="diag-card-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8L8 4 4 8 8 12 12 8z"/><path d="M20 16l-4-4-4 4 4 4 4-4z"/><path d="M8 12l4 4"/></svg>
                            </span>
                            <span class="diag-card-title"><?= e($campaign->name) ?></span>
                        </div>
                        <div class="diag-card-sub"><?= e($campaign->slug) ?></div>
                        <div class="diag-card-bar">
                            <span class="diag-pill <?= $campaign->isActive ? 'diag-pill-success' : '' ?>">
                                <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:<?= $campaign->isActive ? '#3b8a4f' : '#a8a399' ?>"></span>
                                <?= $campaign->isActive ? 'Active' : 'Inactive' ?>
                            </span>
                            <span class="diag-pill diag-pill-mono"><?= count($flows) ?> flows</span>
                        </div>
                        <div class="diag-token">/<?= e($campaign->slug) ?></div>
                    </div>
                </div>

                <!-- ── Column 2: Flows ── -->
                <div class="diag-col diag-col-flows">
                    <div class="diag-col-eyebrow"><?= e(t('campaigns.diagram_col_flows')) ?></div>

                    <?php foreach ($flows as $i => $f): ?>
                        <div id="dg-flow-<?= $i ?>" class="diag-card diag-card-flow <?= $f->isActive ? 'is-active' : 'is-off' ?>" data-active="<?= $f->isActive ? '1' : '0' ?>">
                            <div class="diag-card-head">
                                <span class="diag-card-icon" aria-hidden="true">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 6l-3 3M14 6l3 3M22 18l-3-3M14 18l3-3M3 12h12"/><circle cx="3" cy="12" r="1"/></svg>
                                </span>
                                <span class="diag-card-title"><?= e($f->name) ?></span>
                                <span class="diag-prio">P<?= (int)$f->position ?></span>
                            </div>
                            <div class="diag-card-bar">
                                <span class="diag-pill <?= $f->isActive ? 'diag-pill-success' : '' ?>">
                                    <span style="display:inline-block;width:5px;height:5px;border-radius:50%;background:<?= $f->isActive ? '#3b8a4f' : '#a8a399' ?>"></span>
                                    <?= e($f->isActive ? t('flows.status_active') : t('flows.status_inactive')) ?>
                                </span>
                                <span class="diag-pill diag-pill-schema"><?= e(t('schemas.' . $f->schemaId)) ?></span>
                                <span class="diag-pill diag-pill-mono">w <?= (int)$f->weight ?></span>
                            </div>
                            <div class="diag-filters">
                                <span class="diag-filters-eyebrow"><?= e(t('campaigns.filters_eyebrow')) ?></span>
                                <?php
                                $filters = $f->filters ?? [];
                                if ($filters === []):
                                ?>
                                    <div class="diag-filters-row"><span class="diag-filters-empty"><?= e(t('campaigns.diagram_any_traffic')) ?></span></div>
                                <?php else: ?>
                                    <?php foreach ($filters as $gi => $group): ?>
                                        <div class="diag-filters-row">
                                            <?php if ($gi > 0): ?><span class="diag-cond-or">OR</span><?php endif; ?>
                                            <?php foreach ($group as $cond):
                                                $field = (string)($cond['field'] ?? '?');
                                                $op    = $opSym[$cond['op'] ?? 'eq'] ?? ($cond['op'] ?? '=');
                                                $val   = $cond['value'] ?? '';
                                                if (is_array($val)) $val = implode(',', array_map('strval', $val));
                                                if (is_bool($val))  $val = $val ? '1' : '0';
                                            ?>
                                                <span class="diag-cond">
                                                    <span class="diag-cond-field"><?= e($field) ?></span>
                                                    <span class="diag-cond-op"><?= e((string)$op) ?></span>
                                                    <span class="diag-cond-val"><?= e((string)$val) ?></span>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Trash fallback card (always shown, last in stack) -->
                    <div id="dg-trash" class="diag-card diag-card-trash">
                        <div class="diag-card-head">
                            <span class="diag-card-icon" aria-hidden="true">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                            </span>
                            <span class="diag-card-title"><?= e(t('campaigns.diagram_trash_fallback')) ?></span>
                            <span class="diag-prio diag-prio-trash">⌫</span>
                        </div>
                        <div class="diag-card-bar">
                            <span class="diag-pill" style="background:#fee2e2;color:#7f1d1d;border-color:#fecaca"><?= e($trashLabel) ?></span>
                        </div>
                        <?php if ($trashHasUrl): ?>
                            <div class="diag-offer-url" style="margin-top:8px"><?= e($trashUrl) ?></div>
                        <?php endif; ?>
                        <div class="diag-card-sub" style="margin-top:8px;color:#a8a399">
                            <?= e(t('campaigns.diagram_trash_when')) ?>
                        </div>
                    </div>
                </div>

                <!-- ── Column 3: Targets (offers per flow) ── -->
                <div class="diag-col diag-col-offers">
                    <div class="diag-col-eyebrow"><?= e(t('campaigns.diagram_col_targets')) ?></div>

                    <?php foreach ($flows as $i => $f): ?>
                        <div id="dg-targets-<?= $i ?>" class="diag-offer-group">
                            <?php if ($f->targetType === 'offers' && !empty($f->targetOffers)): ?>
                                <?php foreach ($f->targetOffers as $j => $to):
                                    $offer = $offerLookup[$to['offer_id'] ?? ''] ?? null;
                                    $oname = $offer?->name ?? t('campaigns.diagram_deleted_offer');
                                    $ourl  = $offer?->url ?? '';
                                    $w     = (int)($to['weight'] ?? 0);
                                ?>
                                    <div id="dg-offer-<?= $i ?>-<?= $j ?>" class="diag-card diag-card-offer">
                                        <div class="diag-card-head">
                                            <span class="diag-card-icon" aria-hidden="true">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                            </span>
                                            <span class="diag-card-title"><?= e($oname) ?></span>
                                        </div>
                                        <?php if ($ourl !== ''): ?>
                                            <div class="diag-offer-url" title="<?= e($ourl) ?>"><?= e($ourl) ?></div>
                                        <?php endif; ?>
                                        <div class="diag-weight">
                                            <div class="diag-weight-bar">
                                                <div class="diag-weight-fill" style="width: <?= max(0, min(100, $w)) ?>%"></div>
                                            </div>
                                            <span class="diag-weight-num"><?= $w ?>%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div id="dg-notarget-<?= $i ?>" class="diag-no-target"><?= e(t('campaigns.diagram_no_target')) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <!-- Trash row spacer to align with Trash card on the left -->
                    <div></div>
                </div>
            </div>
            <svg id="diagSvg" class="diag-svg" aria-hidden="true"></svg>
        </div>

        <script>
            // Build connection map from PHP-rendered DOM, then draw bezier curves
            // on a single SVG overlay. Re-runs on tab open + window resize.
            (function () {
                const flowsCount = <?= count($flows) ?>;
                const flowTargets = <?= json_encode(array_map(fn ($f) => [
                    'isActive' => $f->isActive,
                    'targets'  => $f->targetType === 'offers' && !empty($f->targetOffers)
                        ? array_map(fn ($t) => ['weight' => (int)($t['weight'] ?? 0)], $f->targetOffers)
                        : null,
                ], $flows), JSON_UNESCAPED_UNICODE) ?>;

                function rect(id) {
                    const el = document.getElementById(id);
                    return el ? el.getBoundingClientRect() : null;
                }

                function drawEdge(svg, canvasRect, fromR, toR, klass) {
                    const x1 = fromR.right - canvasRect.left;
                    const y1 = fromR.top + fromR.height / 2 - canvasRect.top;
                    const x2 = toR.left - canvasRect.left;
                    const y2 = toR.top + toR.height / 2 - canvasRect.top;
                    const dx = (x2 - x1) * 0.55;
                    const d = `M ${x1} ${y1} C ${x1 + dx} ${y1}, ${x2 - dx} ${y2}, ${x2} ${y2}`;
                    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                    path.setAttribute('d', d);
                    path.setAttribute('class', 'diag-edge ' + klass);
                    svg.appendChild(path);
                    return { x1, y1, x2, y2 };
                }

                function drawLabel(canvas, geo, text, klass) {
                    const lab = document.createElement('div');
                    lab.className = 'diag-edge-label ' + (klass || '');
                    lab.textContent = text;
                    lab.style.left = ((geo.x1 + geo.x2) / 2) + 'px';
                    lab.style.top  = ((geo.y1 + geo.y2) / 2) + 'px';
                    canvas.appendChild(lab);
                }

                window.renderCampaignDiagram = function () {
                    const canvas = document.getElementById('diagCanvas');
                    const svg    = document.getElementById('diagSvg');
                    if (!canvas || !svg) return;

                    // Clear previous overlays
                    svg.innerHTML = '';
                    canvas.querySelectorAll('.diag-edge-label').forEach(n => n.remove());

                    // Resize SVG to canvas
                    const cr = canvas.getBoundingClientRect();
                    svg.setAttribute('width', cr.width);
                    svg.setAttribute('height', canvas.scrollHeight);

                    const campaignR = rect('dg-campaign');
                    if (!campaignR) return;

                    // Campaign → each flow
                    for (let i = 0; i < flowsCount; i++) {
                        const flowR = rect('dg-flow-' + i);
                        if (!flowR) continue;
                        const isActive = flowTargets[i].isActive;
                        const klass = 'diag-edge-flow ' + (isActive ? 'is-active' : 'is-off');
                        const geo = drawEdge(svg, cr, campaignR, flowR, klass);
                        drawLabel(canvas, geo, 'P' + (i + 1), isActive ? '' : 'is-off');

                        // Flow → each offer (or no-target placeholder)
                        if (flowTargets[i].targets) {
                            flowTargets[i].targets.forEach((t, j) => {
                                const offR = rect('dg-offer-' + i + '-' + j);
                                if (!offR) return;
                                const og = drawEdge(svg, cr, flowR, offR, 'diag-edge-offer');
                                drawLabel(canvas, og, t.weight + '%', 'is-offer');
                            });
                        } else {
                            const ntR = rect('dg-notarget-' + i);
                            if (ntR) drawEdge(svg, cr, flowR, ntR, 'diag-edge-no-target');
                        }
                    }

                    // Campaign → Trash (fallback)
                    const trashR = rect('dg-trash');
                    if (trashR) {
                        const geo = drawEdge(svg, cr, campaignR, trashR, 'diag-edge-trash');
                        drawLabel(canvas, geo, 'fallback', 'is-trash');
                    }
                };

                // Render on tab open (Alpine watcher above), on hash-direct, and on resize
                if (location.hash === '#diagram') {
                    setTimeout(() => window.renderCampaignDiagram(), 80);
                }
                let resizeTimer = null;
                window.addEventListener('resize', () => {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(() => window.renderCampaignDiagram(), 120);
                });
            })();
        </script>
    </section>

    <!-- ===== Danger tab ===== -->
    <section x-show="tab === 'danger'" x-cloak class="anim-fade-in" role="tabpanel" style="max-width:560px">
        <h2 class="section-title" style="color:var(--color-danger-strong)"><?= e(t('campaigns.danger_zone')) ?></h2>
        <div style="margin-top:14px;padding:18px;border:1px solid var(--color-danger-border);background:var(--color-danger-bg);border-radius:8px">
            <p style="font-size:0.875rem;color:var(--color-danger-strong);font-family:var(--font-sans);margin-bottom:14px;line-height:1.5">
                <?= tn('campaigns.delete_warn', count($flows), ['flows' => '<strong>' . count($flows) . '</strong>', 'offers' => count($offers)]) ?>
            </p>
            <a href="<?= e(url('/admin/campaigns/' . $campaign->id . '/delete')) ?>" class="btn-danger" style="font-size:0.85rem">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                <?= e(t('campaigns.delete_campaign')) ?>
            </a>
        </div>
    </section>
</div>
<?php endif; ?>
