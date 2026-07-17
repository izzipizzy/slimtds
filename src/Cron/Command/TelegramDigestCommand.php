<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Admin\Repository\CampaignRepository;
use App\Shared\Telegram\TelegramNotifier;
use App\Stats\StatsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'telegram:digest', description: 'Send daily stats digest to Telegram')]
final class TelegramDigestCommand extends Command
{
    public function __construct(
        private readonly TelegramNotifier $tg,
        private readonly StatsRepository $stats,
        private readonly CampaignRepository $campaigns,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Daily digest is a prod ops signal — dev stacks share the same .env
        // (Telegram token + chat) so without this guard `make up` on a laptop
        // would dump dev numbers into the operator's prod channel.
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'dev'));
        if ($env !== 'prod') {
            $output->writeln("telegram:digest skipped (APP_ENV={$env})");
            return self::SUCCESS;
        }

        if (!$this->tg->isConfigured()) {
            $output->writeln('telegram:digest skip — notifier not configured');
            return self::SUCCESS;
        }

        $since = date('Y-m-d\TH:i:sP', strtotime('-24 hours'));

        // Top-line summary across all campaigns
        $totals = $this->stats->summary(null, $since);

        $lines = [
            sprintf(
                '📊 <b>Daily digest</b> — последние 24h',
            ),
            '',
            sprintf(
                'Clicks: <b>%d</b> (uniq: %d, bots: %d)',
                $totals['clicks'],
                $totals['uniq'],
                $totals['bots'],
            ),
            sprintf(
                'Conv: <b>%d</b> (approved: %d) · Payout: <b>$%s</b>',
                $totals['conversions'],
                $totals['approved'],
                number_format((float)$totals['payout'], 2),
            ),
            sprintf(
                'CR: <b>%s%%</b> · EPC: <b>$%s</b>',
                $totals['cr'],
                number_format($totals['epc'], 4),
            ),
        ];

        // Top 10 campaigns by clicks
        $allCampaigns = $this->campaigns->page(1, 200);
        $rows = [];

        foreach ($allCampaigns as $campaign) {
            $s = $this->stats->summary($campaign->id, $since);
            if ($s['clicks'] > 0) {
                $rows[] = ['campaign' => $campaign, 'stats' => $s];
            }
        }

        // Sort by clicks desc, take top 10
        usort($rows, static fn ($a, $b) => $b['stats']['clicks'] <=> $a['stats']['clicks']);
        $rows = array_slice($rows, 0, 10);

        if ($rows !== []) {
            $lines[] = '';
            $lines[] = '<b>Top campaigns (by clicks):</b>';
            foreach ($rows as $i => $row) {
                $c = $row['campaign'];
                $s = $row['stats'];
                $lines[] = sprintf(
                    '%d. <b>%s</b> — %d clicks · %d conv · $%s',
                    $i + 1,
                    $c->name,
                    $s['clicks'],
                    $s['approved'],
                    number_format((float)$s['payout'], 2),
                );
            }
        }

        $msg = implode("\n", $lines);
        $sent = $this->tg->send($msg);

        if ($sent) {
            $output->writeln('telegram:digest sent');
        } else {
            $output->writeln('telegram:digest send failed');
        }

        return self::SUCCESS;
    }
}
