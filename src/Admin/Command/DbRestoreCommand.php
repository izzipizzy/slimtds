<?php

declare(strict_types=1);

namespace App\Admin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'db:restore', description: 'Restore a pg_dump backup file into the database')]
final class DbRestoreCommand extends Command
{
    private const BACKUPS_DIR = '/app/var/backups';

    protected function configure(): void
    {
        $this
            ->addArgument('file',    InputArgument::REQUIRED, 'Path to .dump file (absolute or relative to var/backups/)')
            ->addArgument('confirm', InputArgument::OPTIONAL, 'Pass "yes" to confirm destructive restore');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file    = (string)$input->getArgument('file');
        $confirm = (string)($input->getArgument('confirm') ?? '');

        // Resolve path
        if (!str_starts_with($file, '/')) {
            $file = self::BACKUPS_DIR . '/' . $file;
        }

        if (!file_exists($file)) {
            $output->writeln("<error>db:restore error: file not found: {$file}</error>");
            return self::FAILURE;
        }

        if ($confirm !== 'yes') {
            $output->writeln('<error>db:restore refused: pass "yes" as second argument to confirm destructive restore</error>');
            $output->writeln('  Example: php bin/console db:restore ' . basename($file) . ' yes');
            return self::FAILURE;
        }

        $dbName = $_ENV['DB_NAME'] ?? 'slimtds';

        $env = [
            'PGHOST'     => $_ENV['DB_HOST']     ?? 'db',
            'PGPORT'     => $_ENV['DB_PORT']     ?? '5432',
            'PGDATABASE' => $dbName,
            'PGUSER'     => $_ENV['DB_USER']     ?? 'slimtds',
            'PGPASSWORD' => $_ENV['DB_PASSWORD'] ?? 'slimtds',
        ];

        $output->writeln("<info>db:restore</info> → {$file} into <b>{$dbName}</b>");

        $process = new Process(
            [
                '/usr/bin/pg_restore',
                '--clean',
                '--if-exists',
                '--no-owner',
                '--no-privileges',
                '-d', $dbName,
                $file,
            ],
            null,
            $env,
        );
        $process->setTimeout(null);
        $process->run(static fn ($_type, string $buf) => $output->write($buf));

        $exit = $process->getExitCode();

        // pg_restore exits 1 on non-fatal warnings (e.g. "already exists") — we accept that
        if ($exit !== null && $exit > 1) {
            $output->writeln("<error>pg_restore failed with exit code {$exit}</error>");
            return self::FAILURE;
        }

        $output->writeln('<info>db:restore complete</info>');
        return self::SUCCESS;
    }
}
