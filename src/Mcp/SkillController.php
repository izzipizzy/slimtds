<?php

declare(strict_types=1);

namespace App\Mcp;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Serves SKILL.md so a client installs it with one curl. It holds no secrets and ships in the public repository. */
final class SkillController
{
    private const FILE = __DIR__ . '/../../skills/slimtds-traffic-analysis/SKILL.md';

    public function __construct(private readonly ApiKeyService $keys) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->keys->isEnabled() || !is_file(self::FILE)) {
            return $response->withStatus(404);
        }
        $response->getBody()->write((string)file_get_contents(self::FILE));
        return $response
            ->withHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
