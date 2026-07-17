<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Postback\PostbackOutbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'postback:deliver', description: 'Deliver pending outgoing S2S postbacks (exponential retry)')]
final class PostbackDeliverCommand extends Command
{
    public function __construct(private readonly PostbackOutbox $outbox)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processed = $this->outbox->tick(50);
        $output->writeln("postback:deliver processed={$processed}");
        return self::SUCCESS;
    }
}
