<?php

declare(strict_types=1);

use App\Cron\Command\PartitionsRotateCommand;
use App\Admin\Repository\SettingsRepository;
use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    $this->pdo = pdo();
    $rows = $this->pdo->query(
        "SELECT i.inhrelid::regclass::text AS n FROM pg_inherits i WHERE i.inhparent='stats.rrweb_chunks'::regclass"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $r) { $this->pdo->exec("DROP TABLE IF EXISTS {$r}"); }
});

test('partitions:rotate creates an rrweb_chunks partition for today', function (): void {
    $db = new Connection($this->pdo);
    $cmd = new PartitionsRotateCommand(new Partitions($this->pdo), new SettingsRepository($db), $db);
    (new CommandTester($cmd))->execute([]);

    $today = (new DateTimeImmutable('today'))->format('Y_m_d');
    $exists = (bool)$this->pdo->query(
        "SELECT 1 FROM pg_class WHERE relname = 'rrweb_chunks_{$today}'"
    )->fetchColumn();
    expect($exists)->toBeTrue();
});
