<?php

declare(strict_types=1);

namespace App\Admin\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Redirects unauthenticated requests to /admin/login.
 * Exemptions: /admin/login, /admin/logout (both need pre-auth access).
 */
final class AuthMiddleware implements MiddlewareInterface
{
    private const EXEMPT_PATHS = ['/admin/login', '/admin/logout'];
    private const EXEMPT_PATTERNS = ['#^/admin/lang/[a-z]{2}$#'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (in_array($path, self::EXEMPT_PATHS, true)) {
            return $handler->handle($request);
        }
        foreach (self::EXEMPT_PATTERNS as $pattern) {
            if (preg_match($pattern, $path)) {
                return $handler->handle($request);
            }
        }

        if (!isset($_SESSION['admin_id']) || !is_int($_SESSION['admin_id'])) {
            $res = (new ResponseFactory())->createResponse(302);
            return $res->withHeader('Location', '/admin/login');
        }

        return $handler->handle($request->withAttribute('admin_id', $_SESSION['admin_id']));
    }
}
