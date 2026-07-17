<?php

declare(strict_types=1);

namespace App\Engine;

use App\Shared\Db\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

final class VisitorResolver
{
    public const COOKIE_NAME = 'vu';
    private const COOKIE_TTL = 31_536_000; // 1 year
    private const FP_WINDOW_HOURS = 24;
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private readonly Connection $db) {}

    /**
     * Resolve visitor identity. Mutates $ctx in place; returns true when the
     * caller MUST attach a Set-Cookie header for the new visitor uuid.
     */
    public function resolve(ServerRequestInterface $request, Context $ctx): bool
    {
        $cookies = $request->getCookieParams();
        $existing = $cookies[self::COOKIE_NAME] ?? null;

        if (is_string($existing) && preg_match(self::UUID_REGEX, $existing)) {
            $ctx->visitorUuid = $existing;
            $ctx->isUniqVisitor = false;
            return false;
        }

        $salt = $_ENV['APP_SECRET'] ?? '';
        $accept = $request->getHeaderLine('Accept-Language');
        // SHA-256 hex string — converted to bytea via decode(:h,'hex') in SQL
        $fpHash = hash('sha256', $ctx->ip . '|' . $ctx->userAgent . '|' . $accept . '|' . $salt, false);

        $row = $this->db->fetchOne(
            <<<SQL
                SELECT visitor_uuid::text AS uuid
                FROM stats.visitors_fingerprints
                WHERE fp_hash = decode(:h, 'hex')
                  AND created_at > now() - interval '24 hours'
                ORDER BY created_at DESC
                LIMIT 1
            SQL,
            ['h' => $fpHash],
        );

        if ($row !== null && isset($row['uuid'])) {
            $ctx->visitorUuid = (string) $row['uuid'];
            $ctx->isUniqVisitor = false;
            return false;
        }

        $ctx->visitorUuid = Uuid::uuid7()->toString();
        $ctx->isUniqVisitor = true;

        $this->db->execute(
            "INSERT INTO stats.visitors_fingerprints (fp_hash, visitor_uuid) VALUES (decode(:h, 'hex'), :uuid)",
            ['h' => $fpHash, 'uuid' => $ctx->visitorUuid],
        );

        return true;
    }

    public function attachCookie(ResponseInterface $response, string $uuid, bool $secure = true): ResponseInterface
    {
        $attrs = [
            'Path=/',
            'Max-Age=' . self::COOKIE_TTL,
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($secure) {
            $attrs[] = 'Secure';
        }
        $cookie = self::COOKIE_NAME . '=' . $uuid . '; ' . implode('; ', $attrs);
        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
