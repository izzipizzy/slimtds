<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->pdo = pdo();
});

test('rrweb tables exist with expected shape', function (): void {
    // inbox is UNLOGGED
    $persistence = $this->pdo->query(
        "SELECT relpersistence FROM pg_class WHERE relname = 'rrweb_inbox'"
    )->fetchColumn();
    expect($persistence)->toBe('u'); // u = unlogged

    // chunks is a partitioned parent
    $partkind = $this->pdo->query(
        "SELECT c.relkind FROM pg_class c
         JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname='stats' AND c.relname='rrweb_chunks'"
    )->fetchColumn();
    expect($partkind)->toBe('p'); // p = partitioned table

    // sessions has session_id PK
    $pk = $this->pdo->query(
        "SELECT a.attname
         FROM pg_index i
         JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
         WHERE i.indrelid = 'stats.rrweb_sessions'::regclass AND i.indisprimary"
    )->fetchColumn();
    expect($pk)->toBe('session_id');
});
