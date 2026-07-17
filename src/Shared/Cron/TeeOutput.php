<?php

declare(strict_types=1);

namespace App\Shared\Cron;

use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Output decorator that writes everything to a primary OutputInterface
 * (the real stdout) AND mirrors it to a secondary one (BufferedOutput),
 * so a wrapping LoggedCommand can persist the tail to core.cron_runs.
 *
 * Mirrors Output's protected doWrite() semantics by delegating to write()
 * on both sinks.
 */
final class TeeOutput extends Output
{
    public function __construct(
        private readonly OutputInterface $primary,
        private readonly OutputInterface $secondary,
    ) {
        parent::__construct(
            $primary->getVerbosity(),
            $primary->isDecorated(),
            $primary->getFormatter(),
        );
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->primary->write($message, $newline);
        $this->secondary->write($message, $newline);
    }

    public function setFormatter(OutputFormatterInterface $formatter): void
    {
        parent::setFormatter($formatter);
        $this->primary->setFormatter($formatter);
        $this->secondary->setFormatter($formatter);
    }

    public function setDecorated(bool $decorated): void
    {
        parent::setDecorated($decorated);
        $this->primary->setDecorated($decorated);
        $this->secondary->setDecorated($decorated);
    }

    public function setVerbosity(int $level): void
    {
        parent::setVerbosity($level);
        $this->primary->setVerbosity($level);
        $this->secondary->setVerbosity($level);
    }
}
