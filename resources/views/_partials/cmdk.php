<?php
/**
 * Command palette (⌘K / Ctrl+K). Static client-side filter over the nav items
 * + recent campaigns. Shipped as a once-instantiated Alpine component in admin layout.
 *
 * @var array $cmdkItems  list<{label,href,group,kbd?}>
 */
$cmdkItems = $cmdkItems ?? [];
?>
<div x-data="{
        open: false,
        q: '',
        sel: 0,
        items: <?= htmlspecialchars(json_encode(array_values($cmdkItems), JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,
        get filtered() {
            const q = this.q.toLowerCase().trim();
            if (!q) return this.items;
            return this.items.filter(i =>
                (i.label || '').toLowerCase().includes(q) ||
                (i.group || '').toLowerCase().includes(q)
            );
        },
        get groups() {
            const g = {};
            for (const i of this.filtered) (g[i.group] = g[i.group] || []).push(i);
            return g;
        },
        navigate(item) { this.open = false; window.location.href = item.href; },
        move(d) {
            const total = this.filtered.length;
            if (!total) return;
            this.sel = (this.sel + d + total) % total;
        },
     }"
     @keydown.window.prevent.cmd.k="open = true"
     @keydown.window.prevent.ctrl.k="open = true"
     @keydown.escape.window="open = false"
     x-show="open"
     x-cloak
     class="cmdk-backdrop anim-fade-in"
     @click.self="open = false"
     style="display:none">
    <div class="cmdk" @click.stop>
        <div class="cmdk-input-row">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--color-faint)" aria-hidden="true">
                <circle cx="11" cy="11" r="7"/><path d="M16 16l5 5"/>
            </svg>
            <input class="cmdk-input"
                   placeholder="<?= e(t('cmdk.placeholder') ?? 'Search campaigns, offers, flows…') ?>"
                   x-model="q"
                   @keydown.arrow-down.prevent="move(1)"
                   @keydown.arrow-up.prevent="move(-1)"
                   @keydown.enter.prevent="filtered[sel] && navigate(filtered[sel])"
                   x-ref="cmdkInput"
                   x-init="$watch('open', v => { if (v) $nextTick(() => $refs.cmdkInput.focus()); else q = ''; sel = 0; })">
            <span class="kbd">esc</span>
        </div>
        <div class="cmdk-results">
            <template x-for="(items, group) in groups" :key="group">
                <div>
                    <div class="cmdk-group-label" x-text="group"></div>
                    <template x-for="(item, idx) in items" :key="item.href">
                        <a :href="item.href"
                           class="cmdk-item"
                           :class="{ 'is-selected': filtered.indexOf(item) === sel }"
                           @click.prevent="navigate(item)"
                           @mouseenter="sel = filtered.indexOf(item)">
                            <span x-text="item.label"></span>
                            <span class="cmdk-meta" x-text="item.meta || ''"></span>
                        </a>
                    </template>
                </div>
            </template>
            <template x-if="filtered.length === 0">
                <div style="padding:18px;text-align:center;color:var(--color-faint);font-family:var(--font-sans);font-size:0.85rem">
                    <?= e(t('cmdk.no_results')) ?>
                </div>
            </template>
        </div>
    </div>
</div>
