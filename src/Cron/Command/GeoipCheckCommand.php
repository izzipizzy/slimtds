<?php

declare(strict_types=1);

namespace App\Cron\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'geoip:check', description: 'Check GeoIP MMDB staleness (warn if > 14 days old)')]
final class GeoipCheckCommand extends Command
{
    private const MAX_AGE_SECONDS = 14 * 86400; // 14 days

    /** @var list<string> */
    private const MMDB_PATHS = [
        '/usr/share/GeoIP/GeoLite2-City.mmdb',
        '/usr/share/GeoIP/GeoLite2-ASN.mmdb',
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stale = [];

        foreach (self::MMDB_PATHS as $path) {
            if (!file_exists($path)) {
                $output->writeln("{$path}: <error>missing</error>");
                $stale[] = $path;
                continue;
            }

            $age = time() - (int)filemtime($path);
            if ($age > self::MAX_AGE_SECONDS) {
                $days = (int)($age / 86400);
                $output->writeln("{$path}: <comment>stale ({$days} days old)</comment>");
                $stale[] = $path;
            } else {
                $days = (int)($age / 86400);
                $output->writeln("{$path}: <info>ok ({$days} days old)</info>");
            }
        }

        if ($stale !== []) {
            $output->writeln('<error>GeoIP check FAILED — ' . count($stale) . ' path(s) missing or stale</error>');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
