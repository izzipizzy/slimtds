<?php

/**
 * Helpers.php — macOS case-insensitive filesystem note:
 * On macOS, helpers.php and Helpers.php are the SAME file.
 * This single file therefore contains both the Helpers class (namespaced)
 * AND the global helper functions. On Linux they would be separate files;
 * here we use PHP namespace blocks to keep both in one file.
 */

declare(strict_types=1);

// ── Class: App\Shared\View\Helpers ─────────────────────────────────────────
namespace App\Shared\View {
    /**
     * Internal holder for the View instance currently rendering.
     * Not meant for direct use — global helpers read Helpers::$current.
     */
    final class Helpers
    {
        public static ?View $current = null;
    }
}

// ── Global helper functions ────────────────────────────────────────────────
namespace {
    if (!function_exists('e')) {
        /** Escape HTML. Use in every <?= output by default. */
        function e(mixed $value): string
        {
            return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (!function_exists('t')) {
        /**
         * Translate a key with the current i18n locale.
         * @param array<string,int|string|float> $params
         */
        function t(string $key, array $params = []): string
        {
            $view = \App\Shared\View\Helpers::$current;
            if ($view === null) {
                return $key; // rendering outside view context
            }
            return $view->i18n->t($key, $params);
        }
    }

    if (!function_exists('tn')) {
        /** Plural translation. */
        function tn(string $key, int $count, array $params = []): string
        {
            $view = \App\Shared\View\Helpers::$current;
            if ($view === null) {
                return $key;
            }
            return $view->i18n->tn($key, $count, $params);
        }
    }

    if (!function_exists('asset')) {
        /** Resolve asset URL from Manifest (e.g. asset('app.css')). */
        function asset(string $name): string
        {
            $view = \App\Shared\View\Helpers::$current;
            if ($view === null) {
                return '/assets/' . ltrim($name, '/');
            }
            return $view->assets->url($name);
        }
    }

    if (!function_exists('csrf_field')) {
        /** Render a hidden CSRF input — consume $csrf_token from template scope if available, else fall back to session. */
        function csrf_field(?string $token = null): string
        {
            $token = $token ?? ($_SESSION['csrf_token'] ?? '');
            return '<input type="hidden" name="_csrf" value="' . e($token) . '">';
        }
    }

    if (!function_exists('old')) {
        /**
         * Retrieve a previously submitted form value from a per-request bag
         * populated by SessionMiddleware (which snapshots and clears
         * $_SESSION['_old'] at the start of each request).
         *
         * The middleware-driven snapshot prevents a stale _old bag from one
         * form leaking into a different form on a later page load — the
         * single-static-cache approach doesn't work in FrankenPHP worker mode
         * since statics persist across requests.
         */
        function old(string $field, mixed $default = ''): string
        {
            $bag = $GLOBALS['__old_bag__'] ?? [];
            return is_array($bag) && isset($bag[$field]) ? (string)$bag[$field] : (string)$default;
        }
    }

    if (!function_exists('flash')) {
        /**
         * Read-and-clear a flash message bucket ('success'|'error'|'info'|'warn').
         * Stored as $_SESSION['_flash'][$bucket] = list<string>.
         * @return list<string>
         */
        function flash(string $bucket): array
        {
            $bag = $_SESSION['_flash'][$bucket] ?? [];
            unset($_SESSION['_flash'][$bucket]);
            return is_array($bag) ? array_values(array_map('strval', $bag)) : [];
        }
    }

    if (!function_exists('flash_push')) {
        /** Push a flash message (called by controllers before redirect). */
        function flash_push(string $bucket, string $message): void
        {
            $_SESSION['_flash'][$bucket][] = $message;
        }
    }

    if (!function_exists('url')) {
        /** Build a URL — just a thin prefix helper, so views read nicely. */
        function url(string $path = '/'): string
        {
            return '/' . ltrim($path, '/');
        }
    }

    if (!function_exists('is_active')) {
        /**
         * Returns 'nav-active' if the current request URI starts with $prefix.
         * Falls back to $_SERVER['REQUEST_URI'] (path only, strips query).
         */
        function is_active(string $prefix): string
        {
            $uri  = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
            $uri  = $uri === false ? '/' : $uri;
            // Exact match for dashboard (/admin), prefix match otherwise
            if ($prefix === '/admin') {
                return $uri === '/admin' ? 'nav-active' : '';
            }
            return str_starts_with($uri, $prefix) ? 'nav-active' : '';
        }
    }
}
