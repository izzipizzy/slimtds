<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Shared\Db\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'bots:update', description: 'Refresh core.bot_ips from myip.ms')]
final class BotsUpdateCommand extends Command
{
    private const URL = 'https://myip.ms/files/bots/live_webcrawlers.txt';

    private const SECTION_MAP = [
        'baidu'  => 'baidu',
        'bing'   => 'microsoft',
        'msnbot' => 'microsoft',
        'google' => 'google',
        'mail'   => 'mail',
        'yahoo'  => 'yahoo',
        'yandex' => 'yandex',
    ];

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Override source URL');
        $this->addOption('replace', null, InputOption::VALUE_NONE, 'Truncate before insert (default: upsert)');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $url = (string)($in->getOption('source') ?: self::URL);
        $out->writeln("<info>fetching:</info> {$url}");

        $body = $this->fetch($url);
        if ($body === null) {
            $out->writeln('<error>fetch failed</error>');
            return self::FAILURE;
        }

        $entries = $this->parse($body);
        $out->writeln(sprintf('<info>parsed:</info> %d entries', count($entries)));

        if ($entries === []) {
            $out->writeln('<comment>refusing to wipe bot_ips on empty parse</comment>');
            return self::SUCCESS;
        }

        // myip.ms can list the same IP under multiple sections (e.g. an IP appears
        // both under "# Googlebot" and "# Other"). Postgres ON CONFLICT DO UPDATE
        // forbids touching the same row twice in one INSERT, so de-dupe per IP
        // first — last seen section wins (good enough for MVP).
        $byIp = [];
        foreach ($entries as [$ip, $bot]) {
            $byIp[$ip] = $bot;
        }

        $this->db->transactional(function () use ($byIp, $in): void {
            if ($in->getOption('replace')) {
                $this->db->execute("DELETE FROM core.bot_ips WHERE source = 'myip.ms'");
            }
            $unique = [];
            foreach ($byIp as $ip => $bot) {
                $unique[] = [$ip, $bot];
            }
            foreach (array_chunk($unique, 500) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $i => [$ip, $bot]) {
                    $values[] = "(:ip{$i}, :bot{$i}, 'myip.ms', now())";
                    $params["ip{$i}"] = $ip;
                    $params["bot{$i}"] = $bot;
                }
                $this->db->execute(
                    'INSERT INTO core.bot_ips (ip, bot_name, source, updated_at) VALUES '
                    . implode(',', $values)
                    . ' ON CONFLICT (ip) DO UPDATE SET bot_name = EXCLUDED.bot_name, updated_at = now()',
                    $params,
                );
            }
        });

        $count = (int)$this->db->fetchScalar('SELECT count(*) FROM core.bot_ips');
        $out->writeln("<info>bot_ips total:</info> {$count}");
        return self::SUCCESS;
    }

    private function fetch(string $url): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'slimTDS-bots-update/1.0']]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : (string)$body;
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public function parse(string $body): array
    {
        $entries = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($line[0] === '#') {
                $haystack = strtolower($line);
                $current = null;
                foreach (self::SECTION_MAP as $needle => $name) {
                    if (str_contains($haystack, $needle)) {
                        $current = $name;
                        break;
                    }
                }
                if ($current === null) $current = 'others';
                continue;
            }
            if (filter_var($line, FILTER_VALIDATE_IP)) {
                $entries[] = [$line, $current ?? 'others'];
            }
        }
        return $entries;
    }
}
