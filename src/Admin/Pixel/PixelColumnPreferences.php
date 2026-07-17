<?php

declare(strict_types=1);

namespace App\Admin\Pixel;

/**
 * Per-session column visibility, order and sort prefs for /admin/pixel.
 * Stored in $_SESSION (server-side, Postgres-backed via SessionHandlerInterface).
 */
final class PixelColumnPreferences
{
    /** @var array<string, array{label_key:string,sortable:?string,default:bool}> */
    public const COLUMNS = [
        'time'         => ['label_key' => 'pixel.col.time',         'sortable' => 'pe.created_at',  'default' => true],
        'campaign'     => ['label_key' => 'pixel.col.campaign',     'sortable' => 'cmp.slug',       'default' => true],
        'event_name'   => ['label_key' => 'pixel.col.event_name',   'sortable' => 'pe.event_name',  'default' => true],
        'page_url'     => ['label_key' => 'pixel.col.page_url',     'sortable' => null,             'default' => true],
        'referer'      => ['label_key' => 'pixel.col.referer',      'sortable' => null,             'default' => true],
        'visitor'      => ['label_key' => 'pixel.col.visitor',      'sortable' => null,             'default' => true],
        'fp_js'        => ['label_key' => 'pixel.col.fp_js',        'sortable' => null,             'default' => false],
        'ip'           => ['label_key' => 'pixel.col.ip',           'sortable' => null,             'default' => false],
        'country'      => ['label_key' => 'pixel.col.country',      'sortable' => 'pe.country',     'default' => true],
        'city'         => ['label_key' => 'pixel.col.city',         'sortable' => null,             'default' => false],
        'asn'          => ['label_key' => 'pixel.col.asn',          'sortable' => 'pe.asn',         'default' => false],
        'device'       => ['label_key' => 'pixel.col.device',       'sortable' => 'pe.device',      'default' => true],
        'os'           => ['label_key' => 'pixel.col.os',           'sortable' => 'pe.os',          'default' => false],
        'browser'      => ['label_key' => 'pixel.col.browser',      'sortable' => 'pe.browser',     'default' => true],
        'ua'           => ['label_key' => 'pixel.col.ua',           'sortable' => null,             'default' => false],
        'screen'       => ['label_key' => 'pixel.col.screen',       'sortable' => null,             'default' => false],
        'timezone'     => ['label_key' => 'pixel.col.timezone',     'sortable' => null,             'default' => false],
        'lang'         => ['label_key' => 'pixel.col.lang',         'sortable' => null,             'default' => false],
        'props'        => ['label_key' => 'pixel.col.props',        'sortable' => null,             'default' => false],
    ];

    private const SESSION_KEY  = 'pixel.columns';
    private const SORT_KEY     = 'pixel.sort';
    private const DEFAULT_SORT = ['field' => 'time', 'dir' => 'desc'];

    /** @return list<string> */
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

    /** @param list<string> $orderedVisible */
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
