<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Admin\Repository\SettingsRepository;
use App\Shared\Db\Connection;
use App\Shared\Db\Partitions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'partitions:rotate', description: 'Create future partitions and drop expired ones')]
final class PartitionsRotateCommand extends Command
{
    public function __construct(
        private readonly Partitions $partitions,
        private readonly SettingsRepository $settings,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $this->partitions->setRetention('stats.clicks',                   $this->settings->getInt('retention_clicks_days', 365));
        $this->partitions->setRetention('stats.pixel_events',             $this->settings->getInt('retention_pixel_events_days', 90));
        $this->partitions->setRetention('stats.visitors_fingerprints',    $this->settings->getInt('retention_fingerprints_days', 30));
        $this->partitions->setRetention('stats.rrweb_chunks',             $this->settings->getInt('retention_rrweb_days', 30));

        $created = array_merge(
            $this->partitions->ensureAhead(2),
            $this->partitions->ensureDailyAhead(3),
        );
        $dropped = array_merge(
            $this->partitions->dropOlderThanRetention(),
            $this->partitions->dropDailyOlderThanRetention(),
        );

        // Drop session-index rows whose data partitions are gone.
        $this->db->execute(
            "DELETE FROM stats.rrweb_sessions WHERE last_at < now() - make_interval(days => :d)",
            ['d' => $this->settings->getInt('retention_rrweb_days', 30)],
        );

        $out->writeln(sprintf('<info>created:</info> %d', count($created)));
        foreach ($created as $n) { $out->writeln("  + $n"); }
        $out->writeln(sprintf('<info>dropped:</info> %d', count($dropped)));
        foreach ($dropped as $n) { $out->writeln("  - $n"); }
        return self::SUCCESS;
    }
}
