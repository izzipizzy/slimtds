<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Shared\RateLimit\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

final class BearerAuthMiddleware implements MiddlewareInterface
{
    private const FAILS_PER_MINUTE = 10;
    private const REQUESTS_PER_MINUTE = 120;

    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly RateLimiter $limiter,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Until the owner generates a key the endpoint does not exist.
        if (!$this->keys->isEnabled()) {
            return $this->json(404, 'Not found');
        }
        $presented = preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m) === 1 ? $m[1] : '';
        // A valid key is always accepted, even from a locked-out address: the
        // key space (stds_ + 40 base62) makes guessing hopeless, so the
        // lockout below only needs to bound wrong-key noise. Refusing a
        // correct key here would turn the limiter into a denial-of-service
        // lever against the legitimate client.
        if ($presented !== '' && $this->keys->verify($presented)) {
            $decision = $this->limiter->hit('mcp_key', self::REQUESTS_PER_MINUTE, 60);
            if (!$decision->allowed) {
                return $this->json(429, 'Rate limit exceeded')->withHeader('Retry-After', (string)max(1, $decision->resetAt - time()));
            }
            $this->keys->touch();
            return $handler->handle($request);
        }

        // Missing or wrong key: throttle by REMOTE_ADDR, the same source
        // App\Admin\Middleware\RateLimitMiddleware::resolveIp() trusts for the
        // admin login limiter — never a client-supplied header. This class
        // may not import App\Admin\Middleware (arch test), hence the small
        // duplicated helper below instead of reuse.
        $failKey = 'mcp_fail:' . $this->remoteAddr($request);
        if ($this->limiter->current($failKey, 60) >= self::FAILS_PER_MINUTE) {
            // Already locked out: report it without adding another hit, so a
            // locked-out attacker cannot keep growing the row forever.
            return $this->json(429, 'Too many failed attempts')->withHeader('Retry-After', (string)max(1, $this->currentWindowEnd(60) - time()));
        }
        $this->limiter->hit($failKey, self::FAILS_PER_MINUTE, 60);
        return $this->json(401, 'Invalid or missing API key')->withHeader('WWW-Authenticate', 'Bearer');
    }

    /** Mirrors RateLimitMiddleware::resolveIp() — see the comment in process(). */
    private function remoteAddr(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? '';
        return is_string($ip) ? $ip : '';
    }

    /**
     * The end of the current fixed window, derived exactly as
     * RateLimiter::hit()/current() derive it internally, but without writing
     * a row — needed here only to report Retry-After for a request that must
     * not itself count as a hit.
     */
    private function currentWindowEnd(int $windowSeconds): int
    {
        $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
        return $windowStart + $windowSeconds;
    }

    private function json(int $status, string $message): ResponseInterface
    {
        $res = (new ResponseFactory())->createResponse($status)->withHeader('Content-Type', 'application/json');
        $res->getBody()->write(json_encode(['error' => $message], JSON_THROW_ON_ERROR));
        return $res;
    }
}
