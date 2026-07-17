<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Stats\StatsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'stats:refresh', description: 'Refresh materialized stats views')]
final class StatsRefreshCommand extends Command
{
    public function __construct(private readonly StatsRepository $repo) { parent::__construct(); }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $start = microtime(true);
        $this->repo->refreshClicksHourly();
        $out->writeln(sprintf('<info>stats.clicks_hourly refreshed</info> %.2fs', microtime(true) - $start));
        return self::SUCCESS;
    }
}
