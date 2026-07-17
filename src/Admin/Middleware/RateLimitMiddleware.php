<?php

declare(strict_types=1);

namespace App\Admin\Middleware;

use App\Shared\Auth\AuthEventLogger;
use App\Shared\RateLimit\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Rate-limits by IP, login (from POST body), and cookie token.
 * Limits (per 5-minute window by default):
 *   IP      — RATE_LIMIT_IP (default 10)
 *   login   — RATE_LIMIT_LOGIN (default 5)
 *   cookie  — RATE_LIMIT_COOKIE (default 20, per 60-minute window)
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly AuthEventLogger $audit,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->resolveIp($request);
        $parsed = $request->getParsedBody();
        $login = is_array($parsed) && isset($parsed['login']) && is_string($parsed['login']) ? $parsed['login'] : '';
        $cookies = $request->getCookieParams();
        $cookieToken = $cookies[$_ENV['SESSION_NAME'] ?? 'slimtds_sess'] ?? '';

        $ipLimit     = (int)($_ENV['RATE_LIMIT_IP']     ?? 10);
        $loginLimit  = (int)($_ENV['RATE_LIMIT_LOGIN']  ?? 5);
        $cookieLimit = (int)($_ENV['RATE_LIMIT_COOKIE'] ?? 20);
        $w5m  = 300;
        $w60m = 3600;

        $decisions = [];
        if ($ip !== '') {
            $decisions['ip'] = $this->limiter->hit('ip:' . $ip, $ipLimit, $w5m);
        }
        if ($login !== '') {
            $decisions['login'] = $this->limiter->hit('login:' . strtolower($login), $loginLimit, $w5m);
        }
        if (is_string($cookieToken) && $cookieToken !== '') {
            $decisions['cookie'] = $this->limiter->hit('cookie:' . $cookieToken, $cookieLimit, $w60m);
        }

        foreach ($decisions as $scope => $d) {
            if (!$d->allowed) {
                $this->audit->log(
                    AuthEventLogger::EVENT_RATE_LIMITED,
                    adminLogin: $login !== '' ? $login : null,
                    ip: $ip !== '' ? $ip : null,
                    userAgent: $request->getHeaderLine('User-Agent') ?: null,
                    details: ['scope' => $scope, 'reset_at' => $d->resetAt],
                );
                $res = (new ResponseFactory())->createResponse(429);
                $res->getBody()->write("Rate limit exceeded (scope: {$scope}). Try again later.");
                return $res
                    ->withHeader('Content-Type', 'text/plain')
                    ->withHeader('Retry-After', (string)max(1, $d->resetAt - time()));
            }
        }

        return $handler->handle($request);
    }

    private function resolveIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        // In dev we trust REMOTE_ADDR. In prod behind CF, Caddy already rewrites it.
        $ip = $server['REMOTE_ADDR'] ?? '';
        return is_string($ip) ? $ip : '';
    }
}
