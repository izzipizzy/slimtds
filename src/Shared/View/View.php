<?php

declare(strict_types=1);

namespace App\Shared\View;

use App\Shared\Asset\Manifest;
use App\Shared\I18n\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Minimal native-PHP view renderer.
 * Templates live in resources/views. Layouts wrap content via $content variable.
 * No inheritance; use $layout variable inside templates to pick one.
 */
final class View
{
    /** @var array<string,mixed> */
    private array $flash = [];

    public function __construct(
        public readonly string $viewsDir,
        public readonly Manifest $assets,
        public readonly I18n $i18n,
    ) {}

    /**
     * Render a template with data; returns HTML string.
     * Template sets `$layout` (optional) and `$title` (optional) variables.
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $path = $this->resolvePath($template);

        // Make the View instance reachable to helpers inside the template
        $previous = Helpers::$current ?? null;
        Helpers::$current = $this;

        try {
            // Render child template first (buffer)
            $content = $this->capture($path, $data);

            // If the template set $layout, render it wrapping $content.
            // Explicit null in $data['__layout__'] disables layout (no-layout mode).
            // Missing key falls back to 'layouts/admin' default (only at top-level context).
            $layoutExplicit = array_key_exists('__layout__', $data);
            $layout = $layoutExplicit ? $data['__layout__'] : null;
            if (!$layoutExplicit && $previous === null) {
                $layout = 'layouts/admin';
            }
            // Allow child to override via $data['__layout__']; also via set-layout convention:
            // child template ends with `return ['layout' => 'layouts/public', 'title' => '...'];`
            // We read the child's last-line return value by capturing it via include

            if (is_string($layout)) {
                $layoutPath = $this->resolvePath($layout);
                return $this->capture($layoutPath, array_merge($data, [
                    '__content__' => $content,
                ]));
            }
            return $content;
        } finally {
            Helpers::$current = $previous;
        }
    }

    /**
     * Render into a PSR-7 Response (sets body + Content-Type: text/html).
     * @param array<string,mixed> $data
     */
    public function respond(ResponseInterface $response, string $template, array $data = []): ResponseInterface
    {
        $response->getBody()->write($this->render($template, $data));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** Snapshot a CSRF token into template data from request attributes. */
    public function withRequestContext(ServerRequestInterface $request): array
    {
        return [
            'csrf_token' => (string)($request->getAttribute('csrf_token') ?? ''),
            'lang'       => (string)($request->getAttribute('lang') ?? 'ru'),
            'admin_id'   => $request->getAttribute('admin_id'),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function capture(string $path, array $data): string
    {
        $renderer = static function (string $__path, array $__data): string {
            // Extract template variables into local scope
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__path;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string)ob_get_clean();
        };
        return $renderer($path, $data);
    }

    private function resolvePath(string $template): string
    {
        // Accept 'admin/login' OR 'admin/login.php'
        if (!str_ends_with($template, '.php')) {
            $template .= '.php';
        }
        $path = $this->viewsDir . '/' . ltrim($template, '/');
        if (!is_file($path)) {
            throw new \RuntimeException("view not found: {$template} (looked at {$path})");
        }
        return $path;
    }
}
