<?php

declare(strict_types=1);

namespace App\Postback;

use App\Admin\Repository\OfferRepository;
use App\Engine\Context;
use App\Engine\MacroExpander;
use App\Shared\Db\Connection;

/**
 * Outgoing S2S postback queue.
 *
 * Enqueues one row per postback URL per conversion into core.postback_deliveries,
 * and delivers them via cURL with exponential back-off.
 *
 * Retry schedule (seconds): [60, 300, 1500, 7200, 36000] (1m, 5m, 25m, 2h, 10h).
 * MAX_ATTEMPTS = 5 — after the 5th failure the row is no longer picked up by tick().
 * Total retry budget ≈ 13.5 hours from first failure.
 */
final class PostbackOutbox
{
    private const MAX_ATTEMPTS = 5;

    /** Delay in seconds indexed by 0-based attempt number (0 = first retry after first failure). */
    private const RETRY_DELAYS = [60, 300, 1500, 7200, 36000];

    public function __construct(
        private readonly Connection $db,
        private readonly OfferRepository $offers,
        private readonly MacroExpander $macros,
    ) {}

    /**
     * Enqueue one delivery row per postback URL defined on the offer.
     *
     * @param  array<string,string|null> $vars  click_id, payout, status, external_id, currency
     * @return int  number of rows inserted (0 when offer has no postback URLs)
     */
    public function enqueue(string $conversionId, string $offerId, array $vars): int
    {
        $offer = $this->offers->findById($offerId);
        if ($offer === null || $offer->postbackUrls === []) {
            return 0;
        }

        $count = 0;
        foreach ($offer->postbackUrls as $template) {
            $url = $this->expand($template, $vars);
            $this->db->execute(
                <<<'SQL'
                    INSERT INTO core.postback_deliveries
                        (conversion_id, target_url, next_attempt_at)
                    VALUES
                        (:conversion_id, :target_url, now())
                SQL,
                ['conversion_id' => $conversionId, 'target_url' => $url],
            );
            $count++;
        }
        return $count;
    }

    /**
     * Pull up to $batchSize pending rows and attempt delivery.
     *
     * @return int  number of rows processed
     */
    public function tick(int $batchSize = 20): int
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
                SELECT id, target_url, attempts
                FROM core.postback_deliveries
                WHERE delivered_at IS NULL
                  AND next_attempt_at <= now()
                  AND attempts < :max
                ORDER BY next_attempt_at ASC
                LIMIT :limit
            SQL,
            ['max' => self::MAX_ATTEMPTS, 'limit' => $batchSize],
        );

        foreach ($rows as $row) {
            $this->fireOne(
                (string)$row['id'],
                (string)$row['target_url'],
                (int)$row['attempts'],
            );
        }

        return count($rows);
    }

    /**
     * Fire a single HTTP GET to $url.
     * On 2xx/3xx → mark delivered.
     * On 4xx/5xx/network error → increment attempts, schedule next retry.
     */
    private function fireOne(string $id, string $url, int $attempts): void
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'slimTDS-postback/1.0',
            CURLOPT_SSL_VERIFYPEER => false, // tolerate self-signed certs on partner networks
        ]);

        $body      = curl_exec($ch);
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $responseText = is_string($body) ? substr($body, 0, 500) : '';
        $isSuccess = ($curlError === '' && $httpCode >= 200 && $httpCode < 400);

        if ($isSuccess) {
            $this->db->execute(
                <<<'SQL'
                    UPDATE core.postback_deliveries
                    SET delivered_at           = now(),
                        last_status            = :status,
                        last_response_excerpt  = :excerpt,
                        attempts               = attempts + 1
                    WHERE id = :id
                SQL,
                ['id' => $id, 'status' => $httpCode, 'excerpt' => $responseText],
            );
        } else {
            // Schedule next retry using delay schedule; if attempts >= MAX_ATTEMPTS the worker
            // will no longer pick up the row (tick filter: attempts < MAX_ATTEMPTS).
            $delay = self::RETRY_DELAYS[min($attempts, count(self::RETRY_DELAYS) - 1)];
            $excerpt = $curlError !== '' ? substr($curlError, 0, 500) : $responseText;

            $this->db->execute(
                <<<SQL
                    UPDATE core.postback_deliveries
                    SET attempts              = attempts + 1,
                        next_attempt_at       = now() + interval '{$delay} seconds',
                        last_status           = :status,
                        last_response_excerpt = :excerpt
                    WHERE id = :id
                SQL,
                ['id' => $id, 'status' => $httpCode ?: null, 'excerpt' => $excerpt],
            );
        }
    }

    /**
     * Expand a URL template with postback-specific macro tokens.
     *
     * Handles {payout}, {status}, {external_id}, {currency} directly via str_replace,
     * then delegates remaining tokens (e.g. {click_id}, {utm_*}) to MacroExpander.
     *
     * @param array<string,string|null> $vars
     */
    private function expand(string $template, array $vars): string
    {
        // Direct replacement for postback-specific tokens not in MacroExpander
        $direct = [
            '{payout}'      => (string)($vars['payout']      ?? ''),
            '{status}'      => (string)($vars['status']      ?? ''),
            '{external_id}' => (string)($vars['external_id'] ?? ''),
            '{currency}'    => (string)($vars['currency']    ?? ''),
        ];
        $template = strtr($template, $direct);

        // MacroExpander handles {click_id}, {visitor_uuid}, {utm_*}, etc.
        $ctx          = new Context('0.0.0.0', '-', '-', time());
        $ctx->clickId = (string)($vars['click_id'] ?? '');

        return $this->macros->expand($template, $ctx);
    }
}
