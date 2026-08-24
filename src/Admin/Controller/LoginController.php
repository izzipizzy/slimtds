<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Admin\Repository\AdminRepository;
use App\Shared\Auth\AuthEventLogger;
use App\Shared\Auth\PasswordHasher;
use App\Shared\Version\UpdateStatus;
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
        private readonly UpdateStatus $updateStatus,
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

        $this->queueUpdateToast($view);

        // If must change password, redirect to /admin/password, else dashboard.
        // The flash survives the redirect either way, so the toast still shows
        // on the forced password-change page.
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

    /**
     * One toast per login, owned here rather than by the layout: layout globals
     * render on every page, so a state-driven toast would follow the operator
     * around instead of greeting them once.
     *
     * A failure here queues nothing. A login must never fail because an update
     * check could not be read.
     */
    private function queueUpdateToast(View $view): void
    {
        try {
            $verdict = $this->updateStatus->resolve();
            if (!$verdict->isBehind()) {
                return;
            }
            flash_push('info', sprintf(
                $view->i18n->t('version.toast_behind'),
                (string)$verdict->latestVersion,
            ));
        } catch (\Throwable) {
            // deliberately silent
        }
    }

    private function resolveIp(ServerRequestInterface $request): ?string
    {
        $server = $request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
