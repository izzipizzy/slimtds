<?php

declare(strict_types=1);

namespace App\Pixel;

use App\Admin\Repository\CampaignRepository;
use App\Shared\Db\Connection;
use App\Shared\Http\PixelCors;
use App\Shared\RealIp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /p/rec — receive an rrweb chunk and stage it for the rrweb:flush worker.
 *
 * Body (JSON):
 *   c      string  campaign slug (required)
 *   sid    string  client session id (uuid, required)
 *   seq    int     chunk sequence within the session
 *   events array   rrweb events
 *
 * Responses:
 *   400  missing c/sid
 *   204  accepted (also for unknown slug / oversized — silently dropped)
 */
final class RecordController
{
    /** Hard cap on a single chunk body (2 MiB) — oversized chunks are dropped. */
    private const MAX_BYTES = 2_097_152;

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly Connection $db,
    ) {}

    public function preflight(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return PixelCors::apply($request, $response->withStatus(204));
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw = (string)$request->getBody();
        if (strlen($raw) > self::MAX_BYTES) {
            return PixelCors::apply($request, $response->withStatus(204)); // drop oversized
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = [];
        }

        $slug = isset($data['c']) && is_string($data['c']) && trim($data['c']) !== '' ? trim($data['c']) : null;
        $sid  = isset($data['sid']) && is_string($data['sid']) && trim($data['sid']) !== '' ? trim($data['sid']) : null;
        if ($slug === null || $sid === null) {
            $response->getBody()->write(json_encode(['error' => 'missing c or sid']));
            return PixelCors::apply($request, $response->withStatus(400)->withHeader('Content-Type', 'application/json'));
        }

        // Unknown slug → accept silently (don't leak which slugs exist).
        if ($this->campaigns->findBySlug($slug) === null) {
            return PixelCors::apply($request, $response->withStatus(204));
        }

        $events = isset($data['events']) && is_array($data['events']) ? $data['events'] : [];
        $seq    = isset($data['seq']) && is_numeric($data['seq']) ? (int)$data['seq'] : 0;
        $fp     = isset($data['fp']) && is_string($data['fp']) && $data['fp'] !== '' ? $data['fp'] : null;
        $ref    = isset($data['ref']) && is_string($data['ref']) && $data['ref'] !== '' ? $data['ref'] : null;
        $vu     = $request->getCookieParams()['vu'] ?? null;
        $vu     = is_string($vu) && $vu !== '' ? $vu : null;
        $ip     = RealIp::from($request);
        $ua     = $request->getHeaderLine('User-Agent');

        $this->db->execute(
            <<<'SQL'
                INSERT INTO stats.rrweb_inbox (payload, ip, user_agent, visitor_uuid)
                VALUES (:payload::jsonb, :ip::inet, :ua, :vu)
            SQL,
            [
                'payload' => json_encode(
                    ['c' => $slug, 'sid' => $sid, 'seq' => $seq, 'fp' => $fp, 'ref' => $ref, 'events' => $events],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
                'ua' => $ua !== '' ? $ua : null,
                'vu' => $vu,
            ],
        );

        return PixelCors::apply($request, $response->withStatus(204));
    }
}
