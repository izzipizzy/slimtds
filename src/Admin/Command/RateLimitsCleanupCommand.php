<?php

declare(strict_types=1);

namespace App\Admin\Command;

use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rate_limits:cleanup', description: 'Delete expired rate_limit buckets older than 7 days')]
final class RateLimitsCleanupCommand extends Command
{
    public function __construct(private readonly PDO $pdo)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $n = $this->pdo->exec("DELETE FROM core.rate_limits WHERE window_end < now() - interval '7 days'");
        $out->writeln("<info>deleted:</info> " . (int)$n);
        return self::SUCCESS;
    }
}
