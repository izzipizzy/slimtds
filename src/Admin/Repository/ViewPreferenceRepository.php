<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

/**
 * Per-operator default filter state for a list view (core.user_view_prefs).
 *
 * Only keys the caller declares as allowed are ever stored or returned, so a
 * stale saved preference can never smuggle an unexpected filter into a query.
 */
final class ViewPreferenceRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<string> $allowed
     * @return array<string,string>
     */
    public function get(int $adminId, string $view, array $allowed): array
    {
        $raw = $this->db->fetchScalar(
            'SELECT prefs::text FROM core.user_view_prefs WHERE admin_id = :a AND view = :v',
            ['a' => $adminId, 'v' => $view],
        );
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->only($decoded, $allowed);
    }

    /**
     * @param array<string,mixed> $prefs
     * @param list<string> $allowed
     */
    public function save(int $adminId, string $view, array $prefs, array $allowed): void
    {
        $this->db->execute(
            'INSERT INTO core.user_view_prefs (admin_id, view, prefs, updated_at)
             VALUES (:a, :v, :p::jsonb, now())
             ON CONFLICT (admin_id, view)
             DO UPDATE SET prefs = EXCLUDED.prefs, updated_at = now()',
            [
                'a' => $adminId,
                'v' => $view,
                'p' => json_encode((object)$this->only($prefs, $allowed), JSON_UNESCAPED_UNICODE),
            ],
        );
    }

    public function clear(int $adminId, string $view): void
    {
        $this->db->execute(
            'DELETE FROM core.user_view_prefs WHERE admin_id = :a AND view = :v',
            ['a' => $adminId, 'v' => $view],
        );
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $allowed
     * @return array<string,string>
     */
    private function only(array $values, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $key) {
            $val = $values[$key] ?? null;
            if (is_string($val) && $val !== '') {
                $out[$key] = $val;
            }
        }
        return $out;
    }
}
