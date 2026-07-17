<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Shared\Db\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'demo:reset', description: 'Restore the demo to its baseline (guarded by DEMO_MODE)')]
final class DemoResetCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        if (getenv('DEMO_MODE') !== '1') {
            $out->writeln('<error>refusing: DEMO_MODE is not set to 1 — demo:reset only runs on the demo instance</error>');
            return self::FAILURE;
        }

        $app = $this->getApplication();
        if ($app === null) {
            $out->writeln('<error>demo:reset must run within a console application</error>');
            return self::FAILURE;
        }

        // Required demo config — validated before any destructive work, so a
        // misconfigured instance aborts cleanly instead of half-resetting.
        $login = (string) ($_ENV['ADMIN_LOGIN'] ?? 'admin');
        $password = (string) ($_ENV['ADMIN_PASSWORD'] ?? '');
        if ($password === '') {
            $out->writeln('<error>ADMIN_PASSWORD is not set — cannot reset demo admin to public credentials</error>');
            return self::FAILURE;
        }

        // 1. config baseline + 2. stats baseline (reuse Part 1 commands)
        $code = $app->find('db:seed')->run(new ArrayInput(['--fresh' => true]), $out);
        if ($code !== self::SUCCESS) {
            return $code;
        }
        $code = $app->find('db:seed-stats')->run(new ArrayInput(['--fresh' => true]), $out);
        if ($code !== self::SUCCESS) {
            return $code;
        }

        // 3. clear session / throughput / audit / journal traces
        $this->db->execute('TRUNCATE core.sessions, core.rate_limits, core.auth_events, core.cron_runs, stats.pixel_events_inbox');

        // 4. reset the admin to the public demo credentials (login enabled)
        $code = $app->find('admin:init')->run(new ArrayInput([]), $out);
        if ($code !== self::SUCCESS) {
            return $code;
        }
        $code = $app->find('admin:set-password')->run(new ArrayInput(['login' => $login, 'password' => $password]), $out);
        if ($code !== self::SUCCESS) {
            return $code;
        }

        // 5. wipe settings → all config falls back to code defaults
        $this->db->execute('DELETE FROM core.settings');

        $out->writeln('<info>demo reset complete</info>');
        return self::SUCCESS;
    }
}
