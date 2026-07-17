<?php
/** @var int|null $admin_id */
/** @var int $campaigns_count */
/** @var int $offers_count */
/** @var int $flows_count */
/** @var list<array<string,mixed>> $recent_events */
?>

<!-- Page heading -->
<div style="margin-bottom:32px">
    <h1 class="page-title"><?= e(t('menu.dashboard')) ?></h1>
    <p style="font-size:0.875rem;color:var(--color-stone-500);font-family:var(--font-sans)">
        <?= (int)$campaigns_count ?> <?= e(t('menu.campaigns')) ?> &middot;
        <?= (int)$offers_count ?> <?= e(t('offers.title')) ?> &middot;
        <?= (int)$flows_count ?> <?= e(t('flows.title')) ?>
    </p>
</div>

<!-- Two-column layout -->
<div class="adapt-stack" style="display:grid;grid-template-columns:1fr 1.6fr;gap:24px;align-items:start">

    <!-- Left: dense stats -->
    <div>
        <!-- Stats block -->
        <div style="border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface);overflow:hidden">
            <div style="padding:12px 16px 10px;border-bottom:1px solid var(--color-stone-100)">
                <span style="font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(t('menu.campaigns')) ?></span>
            </div>
            <a href="<?= e(url('/admin/campaigns')) ?>"
               style="display:flex;align-items:baseline;gap:12px;padding:16px 16px 12px;text-decoration:none;transition:background 0.1s">
                <span style="font-family:var(--font-display);font-size:2.75rem;font-weight:600;color:var(--color-stone-900);line-height:1;font-variant-numeric:tabular-nums"><?= (int)$campaigns_count ?></span>
                <span style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(tn('campaigns.count', (int)$campaigns_count)) ?></span>
            </a>

            <div style="border-top:1px solid var(--color-stone-100);padding:12px 16px 10px">
                <span style="font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(t('offers.title')) ?></span>
            </div>
            <div style="display:flex;align-items:baseline;gap:12px;padding:16px 16px 12px">
                <span style="font-family:var(--font-display);font-size:2.75rem;font-weight:600;color:var(--color-stone-900);line-height:1;font-variant-numeric:tabular-nums"><?= (int)$offers_count ?></span>
                <span style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(tn('offers.count', (int)$offers_count)) ?></span>
            </div>

            <div style="border-top:1px solid var(--color-stone-100);padding:12px 16px 10px">
                <span style="font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(t('flows.title')) ?></span>
            </div>
            <div style="display:flex;align-items:baseline;gap:12px;padding:16px 16px 16px">
                <span style="font-family:var(--font-display);font-size:2.75rem;font-weight:600;color:var(--color-stone-900);line-height:1;font-variant-numeric:tabular-nums"><?= (int)$flows_count ?></span>
                <span style="font-size:0.8rem;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(tn('flows.count', (int)$flows_count)) ?></span>
            </div>
        </div>

    </div>

    <!-- Right: auth events feed -->
    <div style="border:1px solid var(--color-stone-200);border-radius:6px;background:var(--color-surface)">
        <div style="padding:12px 16px;border-bottom:1px solid var(--color-stone-100);display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:0.7rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--color-stone-400);font-family:var(--font-sans)"><?= e(t('dashboard.auth_events')) ?></span>
            <span style="font-size:0.7rem;font-family:var(--font-mono);color:var(--color-stone-400)">admin_id=<?= (int)$admin_id ?></span>
        </div>

        <?php if (empty($recent_events)): ?>
            <div style="padding:24px 16px;font-size:0.875rem;color:var(--color-stone-400);font-family:var(--font-sans)">&mdash;</div>
        <?php else: ?>
            <ul style="list-style:none;margin:0;padding:0">
                <?php foreach ($recent_events as $ev): ?>
                    <?php
                    $dotColor = match ((string)$ev['event_type']) {
                        'login_success'   => 'var(--color-success)',
                        'login_fail'      => 'var(--color-danger)',
                        'rate_limited'    => 'var(--color-warn)',
                        'password_change' => 'var(--color-teal-active)',
                        'logout'          => '#a8a399',
                        default           => '#a8a399',
                    };
                    ?>
                    <li style="display:flex;align-items:center;gap:10px;padding:9px 16px;border-bottom:1px solid var(--color-stone-100)">
                        <span style="display:inline-block;width:6px;height:6px;border-radius:50%;flex-shrink:0;background-color:<?= $dotColor ?>"></span>
                        <span style="font-family:var(--font-mono);font-size:0.75rem;color:var(--color-stone-600);min-width:130px"><?= e((string)$ev['event_type']) ?></span>
                        <span style="font-size:0.825rem;color:var(--color-stone-700);font-family:var(--font-sans);flex:1"><?= e((string)($ev['admin_login'] ?? '—')) ?></span>
                        <span style="font-size:0.7rem;font-family:var(--font-mono);color:var(--color-stone-400);white-space:nowrap"><?= e((string)$ev['created_at']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

</div>
