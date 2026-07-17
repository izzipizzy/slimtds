<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

final class SettingsRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return array<string,string> */
    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT key, value FROM core.settings');
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['key']] = (string)$r['value'];
        }
        return $out;
    }

    public function get(string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne('SELECT value FROM core.settings WHERE key = :k', ['k' => $key]);
        return $row === null ? $default : (string)$row['value'];
    }

    public function set(string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO core.settings (key, value) VALUES (:k, :v)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()',
            ['k' => $key, 'v' => $value],
        );
    }

    public function getInt(string $key, int $default): int
    {
        $v = $this->get($key);
        return $v === '' ? $default : (int)$v;
    }

    public function getBool(string $key, bool $default): bool
    {
        $v = $this->get($key, $default ? '1' : '0');
        return $v === '1';
    }
}
