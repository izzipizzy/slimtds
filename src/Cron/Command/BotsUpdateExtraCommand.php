<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Shared\Db\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Pull two public bot-detection lists:
 *   - brianhama/bad-asn-list   — ~700 ASN numbers of hosting/datacenter providers
 *   - X4BNet/lists_vpn         — CIDR ranges for datacenters + commercial VPNs
 *
 * Populates core.bot_asns + core.bot_cidrs. Runs daily.
 */
#[AsCommand(name: 'bots:update-extra', description: 'Refresh core.bot_asns + core.bot_cidrs from brianhama + X4BNet')]
final class BotsUpdateExtraCommand extends Command
{
    private const SOURCES = [
        'brianhama_asn' => 'https://raw.githubusercontent.com/brianhama/bad-asn-list/master/bad-asn-list.csv',
        'x4bnet_dc'     => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/datacenter/ipv4.txt',
        'x4bnet_vpn'    => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/vpn/ipv4.txt',
    ];

    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch + parse but skip writes');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $dryRun = (bool)$in->getOption('dry-run');

        // ── brianhama ASNs ────────────────────────────────────────────
        $body = $this->fetch(self::SOURCES['brianhama_asn']);
        if ($body === null) {
            $out->writeln('<error>fetch failed: brianhama</error>');
            return self::FAILURE;
        }
        $asnRows = $this->parseAsnCsv($body);
        $out->writeln(sprintf('brianhama: parsed %d ASN entries', count($asnRows)));

        // ── X4BNet datacenter CIDRs ───────────────────────────────────
        $body = $this->fetch(self::SOURCES['x4bnet_dc']);
        if ($body === null) {
            $out->writeln('<error>fetch failed: x4bnet datacenter</error>');
            return self::FAILURE;
        }
        $dcCidrs = $this->parseCidrList($body);
        $out->writeln(sprintf('x4bnet datacenter: parsed %d CIDRs', count($dcCidrs)));

        // ── X4BNet VPN CIDRs ──────────────────────────────────────────
        $body = $this->fetch(self::SOURCES['x4bnet_vpn']);
        if ($body === null) {
            $out->writeln('<error>fetch failed: x4bnet vpn</error>');
            return self::FAILURE;
        }
        $vpnCidrs = $this->parseCidrList($body);
        $out->writeln(sprintf('x4bnet vpn: parsed %d CIDRs', count($vpnCidrs)));

        if ($asnRows === [] || $dcCidrs === []) {
            $out->writeln('<comment>refusing to wipe on empty parse</comment>');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $out->writeln('<info>dry-run: skipping writes</info>');
            return self::SUCCESS;
        }

        // ── Persist ───────────────────────────────────────────────────
        $this->db->transactional(function () use ($asnRows, $dcCidrs, $vpnCidrs): void {
            $this->db->execute('DELETE FROM core.bot_asns WHERE source = :s', ['s' => 'brianhama']);
            foreach (array_chunk($asnRows, 500) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $i => [$asn, $name]) {
                    $values[] = "(:a{$i}, :n{$i}, 'brianhama', now())";
                    $params["a{$i}"] = $asn;
                    $params["n{$i}"] = $name;
                }
                $this->db->execute(
                    'INSERT INTO core.bot_asns (asn, name, source, updated_at) VALUES '
                    . implode(',', $values)
                    . ' ON CONFLICT (asn) DO UPDATE SET name = EXCLUDED.name, source = EXCLUDED.source, updated_at = now()',
                    $params,
                );
            }

            $this->db->execute('DELETE FROM core.bot_cidrs WHERE source = :s', ['s' => 'x4bnet']);
            $batches = [
                ['datacenter', $dcCidrs],
                ['vpn',        $vpnCidrs],
            ];
            foreach ($batches as [$kind, $cidrs]) {
                foreach (array_chunk($cidrs, 1000) as $chunk) {
                    $values = [];
                    $params = [];
                    foreach ($chunk as $i => $cidr) {
                        $values[] = "(:c{$i}::cidr, :k{$i}, 'x4bnet', now())";
                        $params["c{$i}"] = $cidr;
                        $params["k{$i}"] = $kind;
                    }
                    $this->db->execute(
                        'INSERT INTO core.bot_cidrs (net, kind, source, updated_at) VALUES '
                        . implode(',', $values)
                        . ' ON CONFLICT (net) DO UPDATE SET kind = EXCLUDED.kind, source = EXCLUDED.source, updated_at = now()',
                        $params,
                    );
                }
            }
        });

        $totalAsn   = (int)$this->db->fetchScalar('SELECT count(*) FROM core.bot_asns');
        $totalCidrs = (int)$this->db->fetchScalar('SELECT count(*) FROM core.bot_cidrs');
        $out->writeln("totals: bot_asns={$totalAsn}, bot_cidrs={$totalCidrs}");

        return self::SUCCESS;
    }

    private function fetch(string $url): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'slimTDS-bots-update/1.0']]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : (string)$body;
    }

    /** @return list<array{0:int,1:?string}> */
    public function parseAsnCsv(string $body): array
    {
        $out = [];
        $isFirst = true;
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($isFirst && stripos($line, 'asn') === 0) { $isFirst = false; continue; }
            $isFirst = false;

            // CSV: ASN,Entity   (Entity may be quoted with commas inside)
            if (preg_match('/^(\d+)\s*,\s*"?(.*?)"?\s*$/', $line, $m)) {
                $out[(int)$m[1]] = [(int)$m[1], $m[2] !== '' ? $m[2] : null];
            }
        }
        return array_values($out); // de-dup on ASN by overwrite
    }

    /** @return list<string> */
    public function parseCidrList(string $body): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+$/', $line)) {
                $out[$line] = true;
            }
        }
        return array_keys($out);
    }
}
