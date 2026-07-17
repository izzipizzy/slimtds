<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use DateTimeImmutable;

final readonly class Admin
{
    public function __construct(
        public int $id,
        public string $login,
        public string $passwordHash,
        public string $uiLang,
        public bool $mustChangePassword,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                  (int)$row['id'],
            login:               (string)$row['login'],
            passwordHash:        (string)$row['password_hash'],
            uiLang:              (string)$row['ui_lang'],
            mustChangePassword:  (bool)$row['must_change_password'],
            createdAt:           new DateTimeImmutable((string)$row['created_at']),
            updatedAt:           new DateTimeImmutable((string)$row['updated_at']),
        );
    }
}
