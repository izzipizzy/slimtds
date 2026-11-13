<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

/**
 * Hardware keys (FIDO2 / WebAuthn) registered per admin.
 * credential_id is stored base64url-encoded, public_key as PEM.
 */
final class WebAuthnCredentialRepository
{
    public function __construct(private readonly Connection $db) {}

    public function countForAdmin(int $adminId): int
    {
        return (int)$this->db->fetchScalar(
            'SELECT count(*) FROM core.admin_webauthn_credentials WHERE admin_id = :a',
            ['a' => $adminId],
        );
    }

    /** @return list<array{id:int,name:string,created_at:string,last_used_at:?string}> */
    public function listForAdmin(int $adminId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, name, created_at, last_used_at FROM core.admin_webauthn_credentials WHERE admin_id = :a ORDER BY id',
            ['a' => $adminId],
        );
        return array_map(static fn (array $r): array => [
            'id'           => (int)$r['id'],
            'name'         => (string)$r['name'],
            'created_at'   => (string)$r['created_at'],
            'last_used_at' => $r['last_used_at'] !== null ? (string)$r['last_used_at'] : null,
        ], $rows);
    }

    /** @return list<string> base64url credential ids */
    public function credentialIdsForAdmin(int $adminId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT credential_id FROM core.admin_webauthn_credentials WHERE admin_id = :a',
            ['a' => $adminId],
        );
        return array_map(static fn (array $r): string => (string)$r['credential_id'], $rows);
    }

    /** @return array{id:int,admin_id:int,public_key:string,sign_count:int,name:string}|null */
    public function findByCredentialId(string $credentialId): ?array
    {
        $r = $this->db->fetchOne(
            'SELECT id, admin_id, public_key, sign_count, name FROM core.admin_webauthn_credentials WHERE credential_id = :c',
            ['c' => $credentialId],
        );
        if ($r === null) {
            return null;
        }
        return [
            'id'         => (int)$r['id'],
            'admin_id'   => (int)$r['admin_id'],
            'public_key' => (string)$r['public_key'],
            'sign_count' => (int)$r['sign_count'],
            'name'       => (string)$r['name'],
        ];
    }

    public function add(int $adminId, string $credentialId, string $publicKeyPem, int $signCount, string $name, ?string $aaguid): void
    {
        $this->db->execute(
            <<<'SQL'
                INSERT INTO core.admin_webauthn_credentials (admin_id, credential_id, public_key, sign_count, name, aaguid)
                VALUES (:a, :c, :pk, :sc, :n, :g)
            SQL,
            ['a' => $adminId, 'c' => $credentialId, 'pk' => $publicKeyPem, 'sc' => $signCount, 'n' => $name, 'g' => $aaguid],
        );
    }

    public function touch(int $id, int $signCount): void
    {
        $this->db->execute(
            'UPDATE core.admin_webauthn_credentials SET sign_count = :sc, last_used_at = now() WHERE id = :id',
            ['sc' => $signCount, 'id' => $id],
        );
    }

    /** Returns the deleted key's name, or null if it did not belong to this admin. */
    public function delete(int $adminId, int $id): ?string
    {
        $name = $this->db->fetchScalar(
            'DELETE FROM core.admin_webauthn_credentials WHERE id = :id AND admin_id = :a RETURNING name',
            ['id' => $id, 'a' => $adminId],
        );
        return is_string($name) ? $name : null;
    }

    public function deleteAllForAdmin(int $adminId): int
    {
        return $this->db->execute(
            'DELETE FROM core.admin_webauthn_credentials WHERE admin_id = :a',
            ['a' => $adminId],
        );
    }
}
