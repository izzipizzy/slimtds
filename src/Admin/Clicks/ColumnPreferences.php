<?php

declare(strict_types=1);

namespace App\Admin\Clicks;

/**
 * Per-session column visibility, order and sort prefs for /admin/clicks.
 * Stored in $_SESSION (server-side, Postgres-backed via SessionHandlerInterface).
 */
final class ColumnPreferences
{
    /** @var array<string, array{label_key:string,sortable:?string,default:bool}> */
    public const COLUMNS = [
        'time'         => ['label_key' => 'clicks.col.time',         'sortable' => 'c.created_at',  'default' => true],
        'campaign'     => ['label_key' => 'clicks.col.campaign',     'sortable' => 'cmp.slug',      'default' => true],
        'flow'         => ['label_key' => 'clicks.col.flow',         'sortable' => 'fl.name',       'default' => true],
        'offer'        => ['label_key' => 'clicks.col.offer',        'sortable' => null,            'default' => true],
        'visitor'      => ['label_key' => 'clicks.col.visitor',      'sortable' => null,            'default' => false],
        'fp_js'        => ['label_key' => 'clicks.col.fp_js',        'sortable' => null,            'default' => false],
        'ip'           => ['label_key' => 'clicks.col.ip',           'sortable' => null,            'default' => true],
        'country'      => ['label_key' => 'clicks.col.country',      'sortable' => 'c.country',     'default' => false],
        'region'       => ['label_key' => 'clicks.col.region',       'sortable' => null,            'default' => false],
        'city'         => ['label_key' => 'clicks.col.city',         'sortable' => null,            'default' => false],
        'asn'          => ['label_key' => 'clicks.col.asn',          'sortable' => null,            'default' => false],
        'isp'          => ['label_key' => 'clicks.col.isp',          'sortable' => null,            'default' => false],
        'device'       => ['label_key' => 'clicks.col.device',       'sortable' => 'c.device',      'default' => true],
        'os'           => ['label_key' => 'clicks.col.os',           'sortable' => 'c.os',          'default' => false],
        'browser'      => ['label_key' => 'clicks.col.browser',      'sortable' => 'c.browser',     'default' => false],
        'lang'         => ['label_key' => 'clicks.col.lang',         'sortable' => null,            'default' => false],
        'is_bot'       => ['label_key' => 'clicks.col.is_bot',       'sortable' => 'c.is_bot',      'default' => true],
        'bot_name'     => ['label_key' => 'clicks.col.bot_name',     'sortable' => null,            'default' => false],
        'is_uniq'      => ['label_key' => 'clicks.col.is_uniq',      'sortable' => null,            'default' => false],
        'ua'           => ['label_key' => 'clicks.col.ua',           'sortable' => null,            'default' => false],
        'referer'      => ['label_key' => 'clicks.col.referer',      'sortable' => null,            'default' => false],
        // Entry source recorded by the pixel — shown next to `referer`, not
        // instead of it, and off by default so the stock view is unchanged.
        'entry_referer' => ['label_key' => 'clicks.col.entry_referer', 'sortable' => null,          'default' => false],
        'utm_source'   => ['label_key' => 'clicks.col.utm_source',   'sortable' => null,            'default' => false],
        'utm_medium'   => ['label_key' => 'clicks.col.utm_medium',   'sortable' => null,            'default' => false],
        'utm_campaign' => ['label_key' => 'clicks.col.utm_campaign', 'sortable' => null,            'default' => false],
        'lander_host'  => ['label_key' => 'clicks.col.lander_host',  'sortable' => 'c.lander_host', 'default' => false],
        'lander_button' => ['label_key' => 'clicks.col.lander_button', 'sortable' => 'c.lander_button', 'default' => false],
        'schema'       => ['label_key' => 'clicks.col.schema',       'sortable' => 'c.schema_id',   'default' => false],
        'out_url'      => ['label_key' => 'clicks.col.out_url',      'sortable' => null,            'default' => false],
        'status'       => ['label_key' => 'clicks.col.status',       'sortable' => 'c.http_status', 'default' => true],
    ];

    private const SESSION_KEY  = 'clicks.columns';
    private const SORT_KEY     = 'clicks.sort';
    private const DEFAULT_SORT = ['field' => 'time', 'dir' => 'desc'];

    /**
     * Ordered list of visible column keys.
     * @return list<string>
     */
    public function visible(): array
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($stored) || empty($stored)) {
            return $this->defaults();
        }
        return array_values(array_filter(
            $stored,
            static fn ($k) => is_string($k) && isset(self::COLUMNS[$k]),
        ));
    }

    /** @return list<string> */
    private function defaults(): array
    {
        $out = [];
        foreach (self::COLUMNS as $key => $meta) {
            if ($meta['default']) $out[] = $key;
        }
        return $out;
    }

    /**
     * Save the user's chosen visible columns in the order they were given.
     * @param list<string> $orderedVisible
     */
    public function setVisible(array $orderedVisible): void
    {
        $clean = array_values(array_filter(
            $orderedVisible,
            static fn ($k) => is_string($k) && isset(self::COLUMNS[$k]),
        ));
        $_SESSION[self::SESSION_KEY] = $clean;
    }

    /** @return array{field:string,dir:string} */
    public function sort(): array
    {
        $stored = $_SESSION[self::SORT_KEY] ?? null;
        if (
            is_array($stored)
            && isset($stored['field'], $stored['dir'])
            && isset(self::COLUMNS[$stored['field']])
            && self::COLUMNS[$stored['field']]['sortable'] !== null
            && in_array($stored['dir'], ['asc', 'desc'], true)
        ) {
            return $stored;
        }
        return self::DEFAULT_SORT;
    }

    public function setSort(string $field, string $dir): void
    {
        $dir = $dir === 'asc' ? 'asc' : 'desc';
        if (!isset(self::COLUMNS[$field]) || self::COLUMNS[$field]['sortable'] === null) {
            $_SESSION[self::SORT_KEY] = self::DEFAULT_SORT;
            return;
        }
        $_SESSION[self::SORT_KEY] = ['field' => $field, 'dir' => $dir];
    }

    public function sortColumn(): ?string
    {
        $f = $this->sort()['field'];
        return self::COLUMNS[$f]['sortable'] ?? null;
    }

    public function reset(): void
    {
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::SORT_KEY]);
    }
}
