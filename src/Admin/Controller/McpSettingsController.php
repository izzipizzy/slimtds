<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\AdminRepository;
use App\Mcp\ApiKeyService;
use App\Shared\Auth\AuthEventLogger;
use App\Shared\RealIp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class McpSettingsController
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly AdminRepository $admins,
        private readonly AuthEventLogger $audit,
    ) {}

    public function generate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // The plaintext exists here and in the next page render, nowhere
        // else — and only for five minutes. Sessions are persisted in
        // Postgres for up to 14 days, so a park that never gets rendered
        // (crash, closed tab) must not leave the key sitting in the database
        // for the whole session lifetime.
        $_SESSION['mcp_new_key'] = ['key' => $this->keys->generate(), 'exp' => time() + 300];
        $this->log(AuthEventLogger::EVENT_MCP_KEY_GENERATED, $request);
        return $this->back($response);
    }

    public function revoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $prefix = $this->keys->status()['prefix'];
        $this->keys->revoke();
        unset($_SESSION['mcp_new_key']);
        $this->log(AuthEventLogger::EVENT_MCP_KEY_REVOKED, $request, $prefix);
        flash_push('success', 'MCP key revoked');
        return $this->back($response);
    }

    public function options(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $this->keys->setFullIp(is_array($body) && isset($body['mcp_full_ip']));
        flash_push('success', 'Settings saved');
        return $this->back($response);
    }

    private function log(string $event, ServerRequestInterface $request, ?string $prefix = null): void
    {
        $this->audit->log(
            $event,
            $this->currentAdminLogin(),
            RealIp::from($request),
            $request->getHeaderLine('User-Agent') ?: null,
            ['prefix' => $prefix ?? $this->keys->status()['prefix']],
        );
    }

    /**
     * Only $_SESSION['admin_id'] is set on login (see LoginController /
     * PasswordController) — the login string itself lives in core.admins, so
     * the audit trail has to look it up rather than reading it from session.
     */
    private function currentAdminLogin(): ?string
    {
        if (!isset($_SESSION['admin_id']) || !is_int($_SESSION['admin_id'])) {
            return null;
        }
        return $this->admins->findById($_SESSION['admin_id'])?->login;
    }

    private function back(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/admin/settings#mcp')->withStatus(302);
    }
}
