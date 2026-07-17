<?php

declare(strict_types=1);

namespace App\Shared\Cron;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Symfony Console Application subclass that records every command
 * invocation in core.cron_runs via CronJournal and tees stdout into
 * a BufferedOutput so the tail can be persisted.
 *
 * Single extension point — doRunCommand() — keeps it transparent for
 * commands themselves, no per-command boilerplate.
 */
final class JournaledApplication extends Application
{
    public function __construct(
        private readonly CronJournal $journal,
        string $name = 'UNKNOWN',
        string $version = 'UNKNOWN',
    ) {
        parent::__construct($name, $version);
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        // Skip framework-internal commands (list, help, completion) to keep
        // the cron_runs table focused on real jobs.
        $name = (string)$command->getName();
        if ($name === 'list' || $name === 'help' || $name === 'completion' || $name === '_complete') {
            return parent::doRunCommand($command, $input, $output);
        }

        $buffer = new BufferedOutput(
            $output->getVerbosity(),
            false, // disable ANSI in the persisted tail
            clone $output->getFormatter(),
        );
        $buffer->getFormatter()->setDecorated(false);

        $tee = new TeeOutput($output, $buffer);

        $id = $this->journal->start($name);
        $code = Command::FAILURE;
        try {
            $code = parent::doRunCommand($command, $input, $tee);
        } catch (\Throwable $e) {
            $tee->writeln('<error>' . $e->getMessage() . '</error>');
            throw $e;
        } finally {
            $this->journal->finish($id, $code, $buffer->fetch());
        }
        return $code;
    }
}
