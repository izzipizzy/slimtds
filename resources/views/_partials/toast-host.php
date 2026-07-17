<?php
/**
 * Toast notification host. Sits in admin layout once. Templates push via
 * Alpine: window.dispatchEvent(new CustomEvent('toast', {detail:{type,msg}}))
 *
 * Also auto-converts server-side flash() into toasts on page load.
 */
?>
<div class="toast-host"
     x-data="{
        toasts: [],
        push(t) {
            const id = Math.random().toString(36).slice(2);
            this.toasts.push({ id, ...t });
            setTimeout(() => this.dismiss(id), t.timeout || 4000);
        },
        dismiss(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
     }"
     @toast.window="push($event.detail)"
     x-init="
        <?php foreach (['success', 'info', 'warn', 'error'] as $bucket): ?>
            <?php foreach (flash($bucket) as $msg): ?>
                push({ type: '<?= e($bucket) ?>', msg: <?= json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP) ?> });
            <?php endforeach; ?>
        <?php endforeach; ?>
     "
     aria-live="polite" aria-atomic="true">
    <template x-for="t in toasts" :key="t.id">
        <div class="toast anim-slide-in"
             :class="'toast-' + (t.type || 'info')"
             role="status">
            <span x-text="t.msg" style="flex:1"></span>
            <button type="button" class="toast-close" @click="dismiss(t.id)" aria-label="<?= e(t('a11y.dismiss')) ?>">×</button>
        </div>
    </template>
</div>
