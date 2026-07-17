<?php

declare(strict_types=1);

namespace App\Admin\Middleware;

use App\Shared\Session\PgSessionHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PgSessionHandler $handler) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_set_save_handler($this->handler, true);

        $name = $_ENV['SESSION_NAME'] ?? 'slimtds_sess';
        $lifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 1_209_600);

        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'httponly' => true,
            'secure' => ($request->getUri()->getScheme() === 'https'),
            'samesite' => 'Lax',
        ]);

        // Pull existing session id from cookie, if any
        $cookies = $request->getCookieParams();
        if (isset($cookies[$name]) && is_string($cookies[$name])) {
            session_id($cookies[$name]);
        }

        session_start();

        // Snapshot and clear _old once per request so the bag from a failed
        // form submission can't leak into a different form on a later page
        // load. (FrankenPHP worker mode keeps statics across requests, so we
        // must snapshot into request-scoped globals, not into a static cache.)
        $GLOBALS['__old_bag__'] = is_array($_SESSION['_old'] ?? null) ? $_SESSION['_old'] : [];
        unset($_SESSION['_old']);

        try {
            return $handler->handle($request);
        } finally {
            unset($GLOBALS['__old_bag__']);
            session_write_close();
        }
    }
}
