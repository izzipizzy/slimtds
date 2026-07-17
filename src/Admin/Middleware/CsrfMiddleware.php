<?php

declare(strict_types=1);

namespace App\Admin\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const SESSION_KEY = 'csrf_token';
    private const FIELD_NAME = '_csrf';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Generate token if missing
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        // Pass token to downstream via attribute (views can read it)
        $request = $request->withAttribute('csrf_token', $_SESSION[self::SESSION_KEY]);

        $method = strtoupper($request->getMethod());
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $parsed = $request->getParsedBody();
            $token  = '';
            if (is_array($parsed) && isset($parsed[self::FIELD_NAME]) && is_string($parsed[self::FIELD_NAME])) {
                $token = $parsed[self::FIELD_NAME];
            } else {
                // fallback: header
                $token = $request->getHeaderLine('X-CSRF-Token');
            }

            $expected = $_SESSION[self::SESSION_KEY] ?? '';
            if ($token === '' || !hash_equals((string)$expected, $token)) {
                $res = (new ResponseFactory())->createResponse(403);
                $res->getBody()->write('CSRF token mismatch');
                return $res->withHeader('Content-Type', 'text/plain');
            }
        }

        return $handler->handle($request);
    }
}
