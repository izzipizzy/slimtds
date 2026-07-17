<?php

declare(strict_types=1);

namespace App\Admin\Middleware;

use App\Admin\Repository\AdminRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LocaleMiddleware implements MiddlewareInterface
{
    private const SUPPORTED = ['ru', 'en'];
    private const DEFAULT = 'ru';

    public function __construct(
        private readonly AdminRepository $admins,
        private readonly \App\Shared\I18n\I18n $i18n,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $lang = self::DEFAULT;

        if (isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id'])) {
            $admin = $this->admins->findById($_SESSION['admin_id']);
            if ($admin !== null && in_array($admin->uiLang, self::SUPPORTED, true)) {
                $lang = $admin->uiLang;
            }
        }

        $cookies = $request->getCookieParams();
        if (isset($cookies['ui_lang']) && in_array($cookies['ui_lang'], self::SUPPORTED, true)) {
            $lang = (string)$cookies['ui_lang'];
        }

        if ($lang === self::DEFAULT) {
            $accept = $request->getHeaderLine('Accept-Language');
            if ($accept !== '' && str_starts_with(strtolower($accept), 'en')) {
                $lang = 'en';
            }
        }

        $this->i18n->setLocale($lang);

        return $handler->handle($request->withAttribute('lang', $lang));
    }
}
