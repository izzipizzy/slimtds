<?php

declare(strict_types=1);

namespace App\Admin\Middleware;

use App\Admin\Repository\AdminRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

final class PasswordChangeRequiredMiddleware implements MiddlewareInterface
{
    private const EXEMPT = ['/admin/password', '/admin/logout'];

    public function __construct(private readonly AdminRepository $admins) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!isset($_SESSION['admin_id']) || !is_int($_SESSION['admin_id'])) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (in_array($path, self::EXEMPT, true)) {
            return $handler->handle($request);
        }
        if (preg_match('#^/admin/lang/[a-z]{2}$#', $path)) {
            return $handler->handle($request);
        }

        $admin = $this->admins->findById($_SESSION['admin_id']);
        if ($admin !== null && $admin->mustChangePassword) {
            $res = (new ResponseFactory())->createResponse(302);
            return $res->withHeader('Location', '/admin/password');
        }

        return $handler->handle($request);
    }
}
