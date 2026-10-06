<?php
declare(strict_types=1);
/** @var array{enabled:bool,prefix:string,created_at:string,last_used_at:string} $mcp_status */
/** @var bool $mcp_full_ip */
/** @var ?string $mcp_new_key */
/** @var string $mcp_origin */
/** @var string $csrf_token */
$placeholder = 'stds_YOUR_KEY';
$k = $mcp_new_key ?? $placeholder;
$url = $mcp_origin . '/mcp';
$snippets = [
    'client_claude'  => "claude mcp add --transport http slimtds {$url} \\\n  --header \"Authorization: Bearer {$k}\"",
    'client_codex'   => "# ~/.codex/config.toml\n[mcp_servers.slimtds]\nurl = \"{$url}\"\nbearer_token_env_var = \"SLIMTDS_API_KEY\"",
    'client_json'    => json_encode(['mcpServers' => ['slimtds' => ['url' => $url, 'headers' => ['Authorization' => "Bearer {$k}"]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    'client_desktop' => json_encode(['mcpServers' => ['slimtds' => ['command' => 'npx', 'args' => ['-y', 'mcp-remote', $url, '--header', "Authorization: Bearer {$k}"]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
];
$codexEnv = "export SLIMTDS_API_KEY=\"{$k}\"";
$skillCmd = static fn (string $home): string =>
    "mkdir -p ~/{$home}/skills/slimtds-traffic-analysis && \\\n  curl -fsSL {$mcp_origin}/mcp/skill -o ~/{$home}/skills/slimtds-traffic-analysis/SKILL.md";
$pre = 'font-family:var(--font-mono);font-size:0.78rem;line-height:1.55;background:var(--color-stone-100);border:1px solid var(--color-border-soft);border-radius:3px;padding:10px 12px;margin:6px 0 0;overflow-x:auto;white-space:pre';
$label = 'font-family:var(--font-sans);font-size:0.72rem;letter-spacing:0.06em;text-transform:uppercase;color:var(--color-muted)';
$copyBtn = static fn (): string =>
    '<button type="button" class="btn btn-ghost" style="font-size:0.72rem;padding:2px 8px" '
    . 'x-data="{ done: false }" '
    . '@click="navigator.clipboard.writeText($el.closest(\'[data-snippet]\').querySelector(\'pre\').textContent); done = true; setTimeout(() => done = false, 1500)" '
    . 'x-text="done ? ' . htmlspecialchars(json_encode(t('settings.mcp.copied')), ENT_QUOTES) . ' : ' . htmlspecialchars(json_encode(t('settings.mcp.copy')), ENT_QUOTES) . '"></button>';
?>
<section x-show="tab === 'mcp'" x-cloak class="anim-fade-in" role="tabpanel" style="max-width:760px">
    <h2 style="font-family:var(--font-display);font-size:1.05rem;font-weight:600;margin:0 0 6px"><?= e(t('settings.mcp.heading')) ?></h2>
    <p style="font-size:0.85rem;color:var(--color-muted);margin:0 0 18px;line-height:1.5;max-width:70ch"><?= e(t('settings.mcp.intro')) ?></p>

    <?php if ($mcp_new_key !== null): ?>
        <div data-snippet style="border:1px solid var(--color-terra-300);border-radius:8px;padding:14px 16px;margin-bottom:18px;background:#fff8f1">
            <div style="display:flex;align-items:center;gap:10px">
                <strong style="font-size:0.9rem"><?= e(t('settings.mcp.new_key_title')) ?></strong>
                <span style="margin-left:auto"><?= $copyBtn() ?></span>
            </div>
            <pre style="<?= $pre ?>;font-size:0.9rem"><?= e($mcp_new_key) ?></pre>
            <p style="font-size:0.8rem;color:var(--color-muted);margin:8px 0 0"><?= e(t('settings.mcp.new_key_hint')) ?></p>
        </div>
    <?php endif; ?>

    <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:28px;margin-bottom:22px">
        <div>
            <div style="<?= $label ?>"><?= e(t('settings.mcp.key')) ?></div>
            <div style="font-size:0.95rem;margin-top:4px">
                <?php if ($mcp_status['enabled']): ?>
                    <span class="meta-mono"><?= e($mcp_status['prefix']) ?>…</span> · <?= e(t('settings.mcp.status_on')) ?>
                <?php else: ?>
                    <?= e(t('settings.mcp.status_off')) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($mcp_status['enabled']): ?>
            <div>
                <div style="<?= $label ?>"><?= e(t('settings.mcp.created')) ?></div>
                <div class="meta-mono" style="margin-top:4px;font-variant-numeric:tabular-nums"><?= e(date('Y-m-d H:i', (int)strtotime($mcp_status['created_at']))) ?></div>
            </div>
            <div>
                <div style="<?= $label ?>"><?= e(t('settings.mcp.last_used')) ?></div>
                <div class="meta-mono" style="margin-top:4px;font-variant-numeric:tabular-nums"><?= $mcp_status['last_used_at'] !== '' ? e(date('Y-m-d H:i', (int)strtotime($mcp_status['last_used_at']))) : e(t('settings.mcp.never')) ?></div>
            </div>
        <?php endif; ?>
        <div style="margin-left:auto;display:flex;gap:8px">
            <form method="post" action="<?= e(url('/admin/settings/mcp/generate')) ?>"
                  <?php if ($mcp_status['enabled']): ?>onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('settings.mcp.confirm_regenerate')), ENT_QUOTES) ?>)"<?php endif; ?>>
                <?= csrf_field($csrf_token) ?>
                <button type="submit" class="btn"><?= e(t($mcp_status['enabled'] ? 'settings.mcp.regenerate' : 'settings.mcp.generate')) ?></button>
            </form>
            <?php if ($mcp_status['enabled']): ?>
                <form method="post" action="<?= e(url('/admin/settings/mcp/revoke')) ?>"
                      onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('settings.mcp.confirm_revoke')), ENT_QUOTES) ?>)">
                    <?= csrf_field($csrf_token) ?>
                    <button type="submit" class="btn btn-ghost"><?= e(t('settings.mcp.revoke')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" action="<?= e(url('/admin/settings/mcp/options')) ?>" style="margin-bottom:26px">
        <?= csrf_field($csrf_token) ?>
        <label style="display:flex;gap:8px;align-items:flex-start;font-size:0.88rem">
            <input type="checkbox" name="mcp_full_ip" value="1" <?= $mcp_full_ip ? 'checked' : '' ?> style="margin-top:3px">
            <span><?= e(t('settings.mcp.full_ip')) ?><br>
                <span style="font-size:0.8rem;color:var(--color-muted)"><?= e(t('settings.mcp.full_ip_hint')) ?></span></span>
        </label>
        <button type="submit" class="btn btn-ghost" style="margin-top:10px"><?= e(t('settings.mcp.save')) ?></button>
    </form>

    <h3 style="font-family:var(--font-display);font-size:0.95rem;font-weight:600;margin:0 0 4px"><?= e(t('settings.mcp.install')) ?></h3>
    <p style="font-size:0.8rem;color:var(--color-muted);margin:0 0 14px;line-height:1.5"><?= t('settings.mcp.install_hint', ['placeholder' => '<span class="meta-mono">' . e($placeholder) . '</span>']) ?></p>

    <?php foreach ($snippets as $key => $code): ?>
        <div data-snippet style="margin-bottom:16px">
            <div style="display:flex;align-items:center"><span style="<?= $label ?>"><?= e(t('settings.mcp.' . $key)) ?></span><span style="margin-left:auto"><?= $copyBtn() ?></span></div>
            <pre style="<?= $pre ?>"><?= e($code) ?></pre>
        </div>
        <?php if ($key === 'client_codex'): ?>
            <div data-snippet style="margin:-8px 0 16px">
                <div style="display:flex;align-items:center"><span style="font-size:0.8rem;color:var(--color-muted)"><?= e(t('settings.mcp.codex_env')) ?></span><span style="margin-left:auto"><?= $copyBtn() ?></span></div>
                <pre style="<?= $pre ?>"><?= e($codexEnv) ?></pre>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <h3 style="font-family:var(--font-display);font-size:0.95rem;font-weight:600;margin:22px 0 4px"><?= e(t('settings.mcp.skill')) ?></h3>
    <p style="font-size:0.8rem;color:var(--color-muted);margin:0 0 12px;line-height:1.5;max-width:70ch"><?= e(t('settings.mcp.skill_hint')) ?></p>
    <?php foreach (['client_claude' => '.claude', 'client_codex' => '.codex'] as $key => $home): ?>
        <div data-snippet style="margin-bottom:16px">
            <div style="display:flex;align-items:center"><span style="<?= $label ?>"><?= e(t('settings.mcp.' . $key)) ?></span><span style="margin-left:auto"><?= $copyBtn() ?></span></div>
            <pre style="<?= $pre ?>"><?= e($skillCmd($home)) ?></pre>
        </div>
    <?php endforeach; ?>

    <p style="font-size:0.8rem;color:var(--color-muted);margin:18px 0 0;line-height:1.6">
        <?= e(t('settings.mcp.web_note')) ?><br><?= e(t('settings.mcp.cf_note')) ?>
    </p>
</section>
