<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Shared\Db\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:vacuum-stats', description: 'VACUUM ANALYZE current-month stats partitions')]
final class DbVacuumCommand extends Command
{
    /** @var list<string> */
    private const BASE_TABLES = [
        'stats.clicks',
        'stats.pixel_events',
        'stats.visitors_fingerprints',
    ];

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $suffix = date('Y_m'); // e.g. 2026_04

        foreach (self::BASE_TABLES as $base) {
            $table = "{$base}_{$suffix}";
            try {
                $this->db->pdo->exec("VACUUM ANALYZE {$table}");
                $output->writeln("<info>vacuumed:</info> {$table}");
            } catch (\Throwable $e) {
                // Partition may not exist — skip, not a failure
                $output->writeln("<comment>skip:</comment> {$table} ({$e->getMessage()})");
            }
        }

        return self::SUCCESS;
    }
}
