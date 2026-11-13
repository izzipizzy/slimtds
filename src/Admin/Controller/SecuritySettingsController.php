<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\Admin;
use App\Admin\Repository\AdminRepository;
use App\Admin\Repository\WebAuthnCredentialRepository;
use App\Shared\Auth\AuthEventLogger;
use App\Shared\Auth\WebAuthnService;
use App\Shared\RealIp;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Hardware keys tab of /admin/settings: enrol and remove YubiKeys.
 * Enrolling the first key switches the admin to key-only sign-in.
 */
final class SecuritySettingsController
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly WebAuthnCredentialRepository $keys,
        private readonly WebAuthnService $webauthn,
        private readonly AuthEventLogger $audit,
    ) {}

    /** POST /admin/settings/security/webauthn/options */
    public function options(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $admin = $this->current();
        if ($admin === null) {
            return $this->json($response, ['error' => 'unauthorized'], 401);
        }
        $options = $this->webauthn->registrationOptions($admin, $request);
        return $this->json($response, ['publicKey' => $options['publicKey']]);
    }

    /** POST /admin/settings/security/webauthn  {name, clientDataJSON, attestationObject} */
    public function register(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $admin = $this->current();
        if ($admin === null) {
            return $this->json($response, ['error' => 'unauthorized'], 401);
        }
        $body = $request->getParsedBody();
        try {
            $name = $this->webauthn->finishRegistration($admin, is_array($body) ? $body : [], $request);
        } catch (\Throwable $e) {
            return $this->json($response, [
                'error' => $view->i18n->t('settings.security.add_failed') . ' (' . mb_substr($e->getMessage(), 0, 120) . ')',
            ], 400);
        }

        // The password can no longer be used, so a pending forced change is moot.
        if ($admin->mustChangePassword) {
            $this->admins->updatePassword($admin->id, $admin->passwordHash);
        }

        $this->log(AuthEventLogger::EVENT_WEBAUTHN_KEY_ADDED, $admin, $request, $name);
        flash_push('success', $view->i18n->t('settings.security.added'));
        return $this->json($response, ['redirect' => '/admin/settings#security']);
    }

    /** POST /admin/settings/security/webauthn/{id}/delete */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, View $view, string $id): ResponseInterface
    {
        $admin = $this->current();
        if ($admin !== null) {
            $name = $this->keys->delete($admin->id, (int)$id);
            if ($name !== null) {
                $this->log(AuthEventLogger::EVENT_WEBAUTHN_KEY_REMOVED, $admin, $request, $name);
                flash_push('success', $view->i18n->t(
                    $this->keys->countForAdmin($admin->id) === 0 ? 'settings.security.removed_last' : 'settings.security.removed',
                ));
            }
        }
        return $response->withHeader('Location', '/admin/settings#security')->withStatus(302);
    }

    private function current(): ?Admin
    {
        $id = $_SESSION['admin_id'] ?? null;
        return is_int($id) ? $this->admins->findById($id) : null;
    }

    private function log(string $event, Admin $admin, ServerRequestInterface $request, string $name): void
    {
        $this->audit->log(
            $event,
            $admin->login,
            RealIp::from($request),
            $request->getHeaderLine('User-Agent') ?: null,
            ['name' => $name],
        );
    }

    /** @param array<string,mixed> $data */
    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }
}
