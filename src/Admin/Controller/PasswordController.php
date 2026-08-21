<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\AdminRepository;
use App\Shared\Auth\AuthEventLogger;
use App\Shared\Auth\PasswordHasher;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PasswordController
{
    private const MIN_LEN = 10;

    public function __construct(
        private readonly AdminRepository $admins,
        private readonly PasswordHasher $hasher,
        private readonly AuthEventLogger $audit,
    ) {}

    public function get(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        if (!isset($_SESSION['admin_id']) || !is_int($_SESSION['admin_id'])) {
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }
        $admin = $this->admins->findById($_SESSION['admin_id']);
        if ($admin === null) {
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }

        $data = array_merge(
            $view->withRequestContext($request),
            [
                'title' => $view->i18n->t('password.title'),
                '__layout__' => 'layouts/admin',
                'forced' => $admin->mustChangePassword,
            ],
        );
        return $view->respond($response, 'admin/password', $data);
    }

    public function post(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        // Demo instances are shared and auto-reset on a timer — if one visitor
        // changes the admin password, everyone else is locked out until the next
        // reset. Reject the change here so the public credentials stay fixed.
        if (getenv('DEMO_MODE') === '1') {
            return $this->fail('password.demo_disabled', [], $response, $view);
        }

        if (!isset($_SESSION['admin_id']) || !is_int($_SESSION['admin_id'])) {
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }

        $admin = $this->admins->findById($_SESSION['admin_id']);
        if ($admin === null) {
            return $response->withHeader('Location', '/admin/login')->withStatus(302);
        }

        $body = $request->getParsedBody();
        $current = is_array($body) && isset($body['current']) && is_string($body['current']) ? $body['current'] : '';
        $new     = is_array($body) && isset($body['new_password']) && is_string($body['new_password']) ? $body['new_password'] : '';
        $confirm = is_array($body) && isset($body['confirm']) && is_string($body['confirm']) ? $body['confirm'] : '';

        $ip = $this->resolveIp($request);
        $ua = $request->getHeaderLine('User-Agent') ?: null;

        // 1. Current password must match
        if (!$this->hasher->verify($current, $admin->passwordHash)) {
            return $this->fail('password.wrong_current', [], $response, $view);
        }

        // 2. New password length
        if (mb_strlen($new) < self::MIN_LEN) {
            return $this->fail('password.too_short', ['min' => self::MIN_LEN], $response, $view);
        }

        // 3. Confirm matches
        if (!hash_equals($new, $confirm)) {
            return $this->fail('password.mismatch', [], $response, $view);
        }

        // 4. Not same as current
        if ($this->hasher->verify($new, $admin->passwordHash)) {
            return $this->fail('password.same_as_old', [], $response, $view);
        }

        // All good — update
        $this->admins->updatePassword($admin->id, $this->hasher->hash($new));
        $this->audit->log(
            AuthEventLogger::EVENT_PASSWORD_CHANGE,
            adminLogin: $admin->login,
            ip: $ip,
            userAgent: $ua,
        );

        flash_push('success', $view->i18n->t('password.changed'));
        return $response->withHeader('Location', '/admin')->withStatus(302);
    }

    /** @param array<string,int|string|float> $params */
    private function fail(string $messageKey, array $params, ResponseInterface $response, View $view): ResponseInterface
    {
        flash_push('error', $view->i18n->t($messageKey, $params));
        return $response->withHeader('Location', '/admin/password')->withStatus(302);
    }

    private function resolveIp(ServerRequestInterface $request): ?string
    {
        $server = $request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
