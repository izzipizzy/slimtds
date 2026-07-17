<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Shared\Db\Connection;

final class AdminRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findById(int $id): ?Admin
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM core.admins WHERE id = :id',
            ['id' => $id],
        );
        return $row === null ? null : Admin::fromRow($row);
    }

    public function findByLogin(string $login): ?Admin
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM core.admins WHERE login = :login',
            ['login' => $login],
        );
        return $row === null ? null : Admin::fromRow($row);
    }

    /** @return list<Admin> */
    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM core.admins ORDER BY id');
        return array_map(Admin::fromRow(...), $rows);
    }

    /**
     * Update the admin password hash and clear must_change_password.
     * Returns true if a row was updated.
     */
    public function updatePassword(int $adminId, string $newHash): bool
    {
        $n = $this->db->execute(
            <<<'SQL'
                UPDATE core.admins
                SET password_hash = :h, must_change_password = false, updated_at = now()
                WHERE id = :id
            SQL,
            ['h' => $newHash, 'id' => $adminId],
        );
        return $n > 0;
    }

    /**
     * Update the admin's UI language preference.
     */
    public function updateUiLang(int $adminId, string $lang): bool
    {
        if (!in_array($lang, ['ru', 'en'], true)) {
            throw new \InvalidArgumentException("unsupported lang: {$lang}");
        }
        $n = $this->db->execute(
            'UPDATE core.admins SET ui_lang = :l, updated_at = now() WHERE id = :id',
            ['l' => $lang, 'id' => $adminId],
        );
        return $n > 0;
    }

    /**
     * Force must_change_password flag to true (e.g. after CLI admin:set-password by sysadmin).
     */
    public function flagPasswordChange(int $adminId): bool
    {
        $n = $this->db->execute(
            'UPDATE core.admins SET must_change_password = true, updated_at = now() WHERE id = :id',
            ['id' => $adminId],
        );
        return $n > 0;
    }
}
