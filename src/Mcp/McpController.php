<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Shared\Db\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class McpController
{
    private const MAX_BODY = 1_000_000;

    public function __construct(
        private readonly JsonRpcDispatcher $dispatcher,
        private readonly ApiKeyService $keys,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw = (string)$request->getBody();
        if (strlen($raw) > self::MAX_BODY) {
            return $response->withStatus(413);
        }
        $answer = $this->dispatcher->handle($raw);
        if ($answer === null) {
            return $response->withStatus(202);
        }
        $response->getBody()->write(json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * No stream to open and no session to end: this server only answers POST.
     * Until a key exists the endpoint doesn't exist either, so GET/DELETE
     * agree with POST and answer 404 rather than advertise it with a 405.
     */
    public function methodNotAllowed(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->keys->isEnabled()) {
            return $response->withStatus(404);
        }
        return $response->withStatus(405)->withHeader('Allow', 'POST');
    }

    /**
     * The guard the dispatcher puts around every tool call: a transaction
     * that cannot write and cannot run long. The tools are read-only by
     * convention already; this guards against a bug in one of them — a tool
     * that issued its own COMMIT, for instance, would otherwise leave the
     * rest of the request free to write.
     */
    public static function readOnlyGuard(Connection $db): \Closure
    {
        return static fn (callable $fn): mixed => $db->transactional(static function () use ($db, $fn): mixed {
            $db->pdo->exec('SET LOCAL transaction_read_only = on');
            $db->pdo->exec("SET LOCAL statement_timeout = '15s'");
            return $fn();
        });
    }
}
