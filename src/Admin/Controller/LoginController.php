<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\AdminRepository;
use App\Shared\Auth\AuthEventLogger;
use App\Shared\Auth\PasswordHasher;
use App\Shared\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles POST /admin/login and GET /admin/logout.
 * GET /admin/login is a view-only handler in routes.php (no behaviour).
 */
final class LoginController
{
    public function __construct(
        private readonly AdminRepository $admins,
        private readonly PasswordHasher $hasher,
        private readonly AuthEventLogger $audit,
    ) {}

    public function postLogin(ServerRequestInterface $request, ResponseInterface $response, View $view): ResponseInterface
    {
        $body = $request->getParsedBody();
        $login = is_array($body) && isset($body['login']) && is_string($body['login']) ? trim($body['login']) : '';
        $pass  = is_array($body) && isset($body['password']) && is_string($body['password']) ? $body['password'] : '';
        $ip    = $this->resolveIp($request);
        $ua    = $request->getHeaderLine('User-Agent') ?: null;

        if ($login === '' || $pass === '') {
            return $this->fail($response, 'auth.wrong_credentials', $login, $ip, $ua, $view);
        }

        $admin = $this->admins->findByLogin($login);
        if ($admin === null) {
            return $this->fail($response, 'auth.wrong_credentials', $login, $ip, $ua, $view);
        }

        if (!$this->hasher->verify($pass, $admin->passwordHash)) {
            return $this->fail($response, 'auth.wrong_credentials', $login, $ip, $ua, $view);
        }

        // Success: rehash if needed, set session, log
        if ($this->hasher->needsRehash($admin->passwordHash)) {
            $this->admins->updatePassword($admin->id, $this->hasher->hash($pass));
        }

        // Regenerate session id on privilege elevation (prevents session fixation)
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $admin->id;
        unset($_SESSION['_old']);

        $this->audit->log(
            AuthEventLogger::EVENT_LOGIN_SUCCESS,
            adminLogin: $admin->login,
            ip: $ip,
            userAgent: $ua,
        );

        // If must change password, redirect to /admin/password, else dashboard
        $target = $admin->mustChangePassword ? '/admin/password' : '/admin';
        return $response->withHeader('Location', $target)->withStatus(302);
    }

    public function getLogout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $adminLogin = null;
        if (isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id'])) {
            $admin = $this->admins->findById($_SESSION['admin_id']);
            $adminLogin = $admin?->login;
        }

        $_SESSION = [];
        // Use regenerate + destroy (rather than session_destroy alone) to also clear the cookie
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            session_destroy();
        }

        $this->audit->log(
            AuthEventLogger::EVENT_LOGOUT,
            adminLogin: $adminLogin,
            ip: $this->resolveIp($request),
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }

    private function fail(
        ResponseInterface $response,
        string $messageKey,
        string $login,
        ?string $ip,
        ?string $ua,
        View $view,
    ): ResponseInterface {
        $_SESSION['_old'] = ['login' => $login];
        flash_push('error', $view->i18n->t($messageKey));

        $this->audit->log(
            AuthEventLogger::EVENT_LOGIN_FAIL,
            adminLogin: $login !== '' ? $login : null,
            ip: $ip,
            userAgent: $ua,
            details: ['reason' => $messageKey],
        );

        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }

    private function resolveIp(ServerRequestInterface $request): ?string
    {
        $server = $request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
