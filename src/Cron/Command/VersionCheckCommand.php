<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Shared\Version\ReleaseFetchException;
use App\Shared\Version\ReleaseFetcher;
use App\Shared\Version\RepoSlug;
use App\Shared\Version\UpdateStatusRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'version:check', description: 'Check upstream for a newer release')]
final class VersionCheckCommand extends Command
{
    public function __construct(
        private readonly UpdateStatusRepository $repo,
        private readonly ReleaseFetcher $fetcher,
        private readonly bool $enabled,
        private readonly string $repoSlug,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        if (!$this->enabled) {
            $out->writeln('<comment>update check disabled (UPDATE_CHECK_ENABLED)</comment>');
            return self::SUCCESS;
        }

        $slug = RepoSlug::tryParse($this->repoSlug);
        if ($slug === null) {
            // A bad slug is a configuration error, not a transient blip: fail
            // loudly so core.cron_runs records it.
            $out->writeln('<error>UPDATE_CHECK_REPO is not a valid owner/repo slug</error>');
            return self::FAILURE;
        }

        if (!$this->repo->tryLock()) {
            $out->writeln('<comment>another check is already running; skipping</comment>');
            return self::SUCCESS;
        }

        $now  = time();
        $etag = null;

        try {
            $state = $this->repo->read();
        } catch (\Throwable $e) {
            $out->writeln('<error>could not read update state: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        // The configured repository changed: drop everything tied to the old
        // one before the request, so a stale representation can never be
        // revalidated against a different repository.
        if ($state !== null && $state->repo !== (string)$slug) {
            $this->repo->resetForRepo((string)$slug, $now);
            $out->writeln("<comment>repository changed to {$slug}; cleared previous state</comment>");
        } elseif ($state !== null) {
            $etag = $state->etagFor((string)$slug);
        }

        try {
            $result = $this->fetcher->fetchLatest($slug, $etag);
        } catch (ReleaseFetchException $e) {
            // Preserve the last good answer; only its freshness ages out, which
            // the `stale` state reports. Still a non-zero exit: a check that
            // has been failing for months must not look healthy.
            $this->repo->recordFailure((string)$slug, $e->getMessage(), $now);
            $out->writeln('<error>update check failed: ' . $e->getMessage() . '</error>');
            return self::FAILURE;
        }

        if ($result->notModified) {
            $this->repo->recordNotModified($now);
            $out->writeln('<info>no change upstream (304)</info>');
            return self::SUCCESS;
        }

        $version = (string)$result->version;
        $this->repo->recordSuccess(
            (string)$slug,
            $version,
            $slug->releaseTagUrl($version),
            $result->publishedAt,
            $result->etag,
            $now,
        );

        $out->writeln("<info>latest upstream release: {$version}</info>");
        return self::SUCCESS;
    }
}
