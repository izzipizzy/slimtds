<?php

declare(strict_types=1);

namespace App\Shared\Cron;

use App\Shared\Db\Connection;

/**
 * Per-invocation journal of bin/console commands so the admin UI can
 * surface "what ran, when, did it succeed, what did it say" without
 * shelling in for supercronic logs.
 *
 * One row per command run. Output is captured by LoggedCommand → TeeOutput
 * (tee'd to both the real output and an in-memory buffer) and the tail is
 * persisted on finish.
 */
final class CronJournal
{
    private const OUTPUT_TAIL_BYTES = 8192;

    public function __construct(private readonly Connection $db) {}

    public function start(string $name): ?int
    {
        try {
            $row = $this->db->fetchOne(
                'INSERT INTO core.cron_runs (name) VALUES (:n) RETURNING id',
                ['n' => $name],
            );
            return $row !== null ? (int)$row['id'] : null;
        } catch (\Throwable $e) {
            // Cron journal must never fail the command itself.
            error_log('[cron-journal] start failed: ' . $e->getMessage());
            return null;
        }
    }

    public function finish(?int $id, int $exitCode, string $output): void
    {
        if ($id === null) return;
        try {
            $tail = $output;
            if (strlen($tail) > self::OUTPUT_TAIL_BYTES) {
                $tail = '…' . substr($tail, -(self::OUTPUT_TAIL_BYTES - 1));
            }
            $this->db->execute(
                "UPDATE core.cron_runs
                 SET finished_at = now(),
                     duration_ms = (EXTRACT(EPOCH FROM (now() - started_at)) * 1000)::int,
                     exit_code   = :ec,
                     output_tail = :ot
                 WHERE id = :id",
                ['ec' => $exitCode, 'ot' => $tail, 'id' => $id],
            );
        } catch (\Throwable $e) {
            error_log('[cron-journal] finish failed: ' . $e->getMessage());
        }
    }
}
