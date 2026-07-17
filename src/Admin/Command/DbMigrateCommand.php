<?php

declare(strict_types=1);

namespace App\Admin\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'db:migrate', description: 'Run Phinx migrations')]
final class DbMigrateCommand extends Command
{
    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $p = new Process(['vendor/bin/phinx', 'migrate', '-c', 'phinx.php']);
        $p->setTimeout(null);
        $p->setTty(false);
        $p->run(static fn ($_type, string $buf) => $out->write($buf));
        return $p->getExitCode() ?? 1;
    }
}
