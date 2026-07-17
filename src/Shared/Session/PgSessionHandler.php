<?php

declare(strict_types=1);

namespace App\Shared\Session;

use App\Shared\Db\Connection;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;

final class PgSessionHandler implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly int $lifetimeSeconds = 1_209_600, // 14 days default
    ) {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string
    {
        $row = $this->db->fetchOne(
            'SELECT data FROM core.sessions WHERE id = :id AND expires_at > now()',
            ['id' => $id],
        );
        if ($row === null) {
            return '';
        }
        $data = $row['data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        return (string)$data;
    }

    public function write(string $id, string $data): bool
    {
        $expiresAt = date('c', time() + $this->lifetimeSeconds);
        $this->db->execute(
            <<<'SQL'
                INSERT INTO core.sessions (id, data, expires_at, updated_at)
                VALUES (:id, :data, :expires_at, now())
                ON CONFLICT (id) DO UPDATE
                SET data = EXCLUDED.data,
                    expires_at = EXCLUDED.expires_at,
                    updated_at = now()
            SQL,
            ['id' => $id, 'data' => $data, 'expires_at' => $expiresAt],
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->execute('DELETE FROM core.sessions WHERE id = :id', ['id' => $id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->db->execute('DELETE FROM core.sessions WHERE expires_at < now()');
    }

    public function create_sid(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function validateId(string $id): bool
    {
        $count = $this->db->fetchScalar(
            'SELECT count(*) FROM core.sessions WHERE id = :id AND expires_at > now()',
            ['id' => $id],
        );
        return (int)$count > 0;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
