<?php

declare(strict_types=1);

namespace App\Cron\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'db:backup', description: 'Create pg_dump backup with 14-day rotation')]
final class DbBackupCommand extends Command
{
    private const BACKUPS_DIR   = '/app/var/backups';
    private const RETENTION_DAYS = 14;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = self::BACKUPS_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            $output->writeln("<error>Cannot create backups directory: {$dir}</error>");
            return self::FAILURE;
        }

        $ts       = date('Y-m-d_H-i-s');
        $dumpFile = "{$dir}/{$ts}.dump";

        $env = [
            'PGHOST'     => $_ENV['DB_HOST']     ?? 'db',
            'PGPORT'     => $_ENV['DB_PORT']     ?? '5432',
            'PGDATABASE' => $_ENV['DB_NAME']     ?? 'slimtds',
            'PGUSER'     => $_ENV['DB_USER']     ?? 'slimtds',
            'PGPASSWORD' => $_ENV['DB_PASSWORD'] ?? 'slimtds',
        ];

        $output->writeln("db:backup starting → {$dumpFile}");

        $process = new Process(
            ['/usr/bin/pg_dump', '--format=custom', "--file={$dumpFile}"],
            null,
            $env,
        );
        $process->setTimeout(null);
        $process->run(static fn ($_type, string $buf) => $output->write($buf));

        $exit = $process->getExitCode();
        if ($exit !== 0) {
            $output->writeln("<error>pg_dump failed with exit code {$exit}</error>");
            // Remove partial file if it exists
            if (file_exists($dumpFile)) {
                unlink($dumpFile);
            }
            return self::FAILURE;
        }

        $size = filesize($dumpFile);
        $output->writeln(sprintf(
            '<info>db:backup OK</info> — %s (%.1f KB)',
            basename($dumpFile),
            (int)$size / 1024,
        ));

        // 14-day rotation: remove old .dump files
        $cutoff = time() - (self::RETENTION_DAYS * 86400);
        $dumps  = glob("{$dir}/*.dump") ?: [];
        $removed = 0;
        foreach ($dumps as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $removed++;
                $output->writeln('<comment>rotated:</comment> ' . basename($file));
            }
        }
        if ($removed > 0) {
            $output->writeln("db:backup rotation removed {$removed} old dump(s)");
        }

        return self::SUCCESS;
    }
}
