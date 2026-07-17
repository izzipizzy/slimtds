<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\SettingsRepository;
use App\Shared\Db\Connection;
use App\Shared\Notification\NotificationRegistry;
use App\Shared\Referer\SearchEngine;
use App\Shared\Telegram\TelegramNotifier;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SettingsController
{
    // Same path the cron's db:backup writes to. Mounted as ./var/backups on host.
    private const BACKUPS_DIR = '/app/var/backups';

    private const RETENTION_KEYS = [
        'retention_clicks_days'       => ['min' => 1,  'max' => 3650, 'default' => 365],
        'retention_pixel_events_days' => ['min' => 1,  'max' => 3650, 'default' => 90],
        'retention_fingerprints_days' => ['min' => 1,  'max' => 365,  'default' => 30],
        'retention_auth_events_days'  => ['min' => 30, 'max' => 3650, 'default' => 180],
        'retention_rrweb_days'        => ['min' => 1,  'max' => 3650, 'default' => 30],
    ];

    public function __construct(
        private readonly SettingsRepository $repo,
        private readonly Connection $db,
        private readonly NotificationRegistry $notifications,
        private readonly TelegramNotifier $tg,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $errors = [];
        if (strtoupper($request->getMethod()) === 'POST') {
            $body = $request->getParsedBody();
            if (is_array($body)) {
                if (($body['section'] ?? '') === 'notifications') {
                    $this->saveNotifications($body);
                    flash_push('success', 'Notifications saved');
                    return $response->withHeader('Location', '/admin/settings#notifications')->withStatus(302);
                }
                foreach (self::RETENTION_KEYS as $key => $bounds) {
                    if (!isset($body[$key])) {
                        continue;
                    }
                    $val = (int)$body[$key];
                    if ($val < $bounds['min'] || $val > $bounds['max']) {
                        $errors[$key] = "must be between {$bounds['min']} and {$bounds['max']}";
                        continue;
                    }
                    $this->repo->set($key, (string)$val);
                }
                if (isset($body['rrweb_sample_rate'])) {
                    $rate = (int)$body['rrweb_sample_rate'];
                    if ($rate < 0 || $rate > 100) {
                        $errors['rrweb_sample_rate'] = 'must be between 0 and 100';
                    } else {
                        $this->repo->set('rrweb_sample_rate', (string)$rate);
                    }
                }
                if (empty($errors)) {
                    flash_push('success', 'Settings saved');
                    return $response->withHeader('Location', '/admin/settings')->withStatus(302);
                }
            }
        }

        // ── Backups inventory ───────────────────────────────────────────
        $backups = $this->listDumps();
        usort($backups, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        $backupTotal = array_sum(array_column($backups, 'size'));

        // ── Cron journal: last N invocations grouped by command name ────
        $cronLatest = $this->db->fetchAll(
            "SELECT DISTINCT ON (name)
                    name, id, started_at, finished_at, duration_ms, exit_code, output_tail
             FROM core.cron_runs
             ORDER BY name, started_at DESC"
        );
        $cronRecent = $this->db->fetchAll(
            "SELECT id, name, started_at, finished_at, duration_ms, exit_code,
                    LEFT(coalesce(output_tail,''), 600) AS preview
             FROM core.cron_runs
             ORDER BY started_at DESC
             LIMIT 50"
        );

        $values = $this->repo->all();
        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title'         => 'Settings',
                '__layout__'    => 'layouts/admin',
                'values'        => $values,
                'errors'        => $errors,
                'bounds'        => self::RETENTION_KEYS,
                'backups'       => $backups,
                'backups_total' => $backupTotal,
                'backups_dir'   => self::BACKUPS_DIR,
                'cron_latest'   => $cronLatest,
                'cron_recent'   => $cronRecent,
                'notif_defs'    => $this->notifications->definitions(),
                'notif_engines' => SearchEngine::keys(),
                'tg_configured' => $this->tg->isConfigured(),
            ],
        );
        return $view->respond($response, 'admin/settings/index', $data);
    }

    /**
     * Persist the Notifications tab form. Checkboxes are present only when
     * checked, so absence = '0'. Sources arrive as name="..._sources[]".
     *
     * @param array<string,mixed> $body
     */
    private function saveNotifications(array $body): void
    {
        $keys = [
            NotificationRegistry::AI_CLICK,
            NotificationRegistry::AI_PIXEL,
            NotificationRegistry::CONV_CLICK,
            NotificationRegistry::CONV_PING,
        ];
        $defs = $this->notifications->definitions();
        $validEngines = SearchEngine::keys();

        foreach ($keys as $key) {
            $this->repo->set("notif_{$key}_enabled", isset($body["notif_{$key}_enabled"]) ? '1' : '0');
            $this->repo->set("notif_{$key}_template", (string)($body["notif_{$key}_template"] ?? ''));

            if (($defs[$key]['has_sources'] ?? false) === true) {
                $raw = $body["notif_{$key}_sources"] ?? [];
                $selected = is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
                $clean = array_values(array_intersect($validEngines, $selected));
                $this->repo->set("notif_{$key}_sources", json_encode($clean, JSON_UNESCAPED_SLASHES));
            }
        }
    }

    public function testTelegram(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->tg->isConfigured()) {
            flash_push('error', 'Telegram not configured (set TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID)');
        } else {
            $ok = $this->tg->send('✅ slimTDS test notification');
            flash_push($ok ? 'success' : 'error', $ok ? 'Test message sent' : 'Test failed — check token/chat_id');
        }
        return $response->withHeader('Location', '/admin/settings#notifications')->withStatus(302);
    }

    /** @return list<array{name:string, size:int, mtime:int}> */
    private function listDumps(): array
    {
        $files = glob(self::BACKUPS_DIR . '/*.dump') ?: [];
        $rows = [];
        foreach ($files as $f) {
            $rows[] = [
                'name'  => basename($f),
                'size'  => (int)(filesize($f) ?: 0),
                'mtime' => (int)(filemtime($f) ?: 0),
            ];
        }
        return $rows;
    }
}
