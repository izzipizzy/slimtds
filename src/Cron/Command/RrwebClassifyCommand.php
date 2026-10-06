<?php

declare(strict_types=1);

namespace App\Cron\Command;

use App\Shared\Db\Connection;
use App\Shared\Rrweb\Interaction;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'rrweb:classify', description: 'Classify interaction in historical recordings without changing replay data')]
final class RrwebClassifyCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Process all currently unclassified sessions in batches');
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Sessions per batch (1–500)', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $size = max(1, min(500, (int)$input->getOption('batch')));
        $after = '00000000-0000-0000-0000-000000000000';
        $total = 0;
        do {
            $rows = $this->db->fetchAll(
                'SELECT session_id, chunk_count FROM stats.rrweb_sessions
                 WHERE has_interaction IS NULL AND session_id > :after::uuid
                 ORDER BY session_id LIMIT :lim',
                ['after' => $after, 'lim' => $size],
            );
            if ($rows === []) break;
            $total += $this->classify($rows);
            $after = (string)$rows[count($rows) - 1]['session_id'];
            $output->writeln('classified=' . $total . ' cursor=' . $after);
        } while ($input->getOption('all'));
        return self::SUCCESS;
    }

    /** @param list<array<string,mixed>> $sessions */
    public function classify(array $sessions): int
    {
        if ($sessions === []) return 0;
        $params = [];
        $in = [];
        $state = [];
        foreach ($sessions as $i => $session) {
            $id = (string)$session['session_id'];
            $in[] = ':s' . $i;
            $params['s' . $i] = $id;
            $state[$id] = ['active' => false, 'valid' => true, 'seen' => 0, 'expected' => (int)$session['chunk_count']];
        }
        // Stream payloads; never load the entire recordings table into memory.
        $stmt = $this->db->run(
            "SELECT session_id, encode(payload, 'base64') AS b64 FROM stats.rrweb_chunks
             WHERE session_id IN (" . implode(',', $in) . ')',
            $params,
        );
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $id = (string)$row['session_id'];
            $state[$id]['seen']++;
            if ($state[$id]['active']) continue;
            $gz = base64_decode((string)$row['b64'], true);
            $json = $gz === false || strlen($gz) < 18 || substr($gz, 0, 3) !== "\x1f\x8b\x08"
                ? false : @gzdecode($gz);
            $events = $json === false ? null : json_decode($json, true);
            if (!is_array($events) || !array_is_list($events)) {
                $state[$id]['valid'] = false;
                continue;
            }
            $state[$id]['active'] = Interaction::exists($events);
        }
        return $this->db->transactional(function () use ($state): int {
            $updated = 0;
            foreach ($state as $id => $s) {
                // Missing/corrupt chunks cannot establish absence of interaction.
                if (!$s['active'] && (!$s['valid'] || $s['seen'] === 0 || $s['seen'] !== $s['expected'])) continue;
                // Compare chunk_count to protect a session still receiving chunks.
                $updated += $this->db->execute(
                    'UPDATE stats.rrweb_sessions SET has_interaction = :active::boolean
                     WHERE session_id = :sid AND has_interaction IS NULL AND chunk_count = :count',
                    ['active' => $s['active'] ? 'true' : 'false', 'sid' => $id, 'count' => $s['expected']],
                );
            }
            return $updated;
        });
    }
}
