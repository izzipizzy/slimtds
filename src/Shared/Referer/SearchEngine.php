<?php

declare(strict_types=1);

namespace App\Shared\Referer;

/**
 * Classifies a referer URL as coming from a known search engine, and emits
 * SQL fragments for filtering log tables (`stats.clicks.referer`,
 * `stats.pixel_events.referer`) by engine. Patterns are host-substring matches
 * applied with ILIKE — false positives are negligible for this use case
 * (operators want a quick "show me search-engine traffic" lens).
 */
final class SearchEngine
{
    /**
     * Engine key → list of host-substring patterns (lowercase). Order of keys
     * is the order shown in the UI dropdown.
     *
     * @var array<string, list<string>>
     */
    public const ENGINES = [
        // AI assistants — listed before traditional engines so e.g. gemini.google.com
        // doesn't get swallowed by the bare 'google' pattern.
        'chatgpt'    => ['chatgpt.com', 'chat.openai.com'],
        'perplexity' => ['perplexity.ai'],
        'claude'     => ['claude.ai'],
        'gemini'     => ['gemini.google.com'],
        'copilot'    => ['copilot.microsoft.com', 'bing.com/chat'],
        // Traditional search engines.
        'google'     => ['.google.', '//google.', 'webcache.googleusercontent'],
        'bing'       => ['bing.com'],
        'baidu'      => ['baidu.com', 'baidu.cn'],
        'yandex'     => ['yandex.', 'ya.ru/'],
        'duckduckgo' => ['duckduckgo.com'],
        'yahoo'      => ['search.yahoo.', 'r.yahoo.', 'yandex.com.tr'],
        'naver'      => ['naver.com', 'naver.jp'],
        'ecosia'     => ['ecosia.org'],
        'brave'      => ['search.brave.com'],
        'qwant'      => ['qwant.com'],
        'seznam'     => ['seznam.cz'],
        'startpage'  => ['startpage.com'],
    ];

    /** Returns engine key (e.g. "google") or null. */
    public static function classify(?string $referer): ?string
    {
        if ($referer === null) return null;
        $r = strtolower($referer);
        if ($r === '') return null;
        foreach (self::ENGINES as $engine => $patterns) {
            foreach ($patterns as $p) {
                if (str_contains($r, $p)) return $engine;
            }
        }
        return null;
    }

    /** @return list<string> engine keys for UI */
    public static function keys(): array
    {
        return array_keys(self::ENGINES);
    }

    /**
     * Prefer the latest nearby pageview's entry referer and fall back to the
     * Referer header recorded on the click.
     */
    public static function clickEntryRefererSql(string $clickAlias = 'c'): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/i', $clickAlias)) {
            throw new \InvalidArgumentException('Invalid SQL alias');
        }

        return "COALESCE((
            SELECT pe.referer
            FROM stats.pixel_events pe
            WHERE pe.visitor_uuid = {$clickAlias}.visitor_uuid
              AND pe.created_at >= {$clickAlias}.created_at - interval '24 hours'
              AND pe.created_at <= {$clickAlias}.created_at + interval '5 minutes'
            ORDER BY pe.created_at DESC
            LIMIT 1
        ), {$clickAlias}.referer)";
    }

    /**
     * Build a WHERE fragment + bind params that matches the requested filter.
     * `$value` is one of:
     *   - 'any'       — match any known engine
     *   - <engine>    — match a single engine by key
     *   - 'none'      — match referers that do NOT belong to any known engine
     *                   (still requires a non-empty referer)
     * For unknown values, returns ['', []] so callers can no-op.
     *
     * @param string $col fully-qualified column reference, e.g. "c.referer"
     * @param string $paramPrefix unique prefix for bind names (avoids clashes)
     * @return array{0:string, 1:array<string,string>}
     */
    public static function sqlFilter(string $value, string $col, string $paramPrefix = 'se'): array
    {
        if ($value === '') return ['', []];

        if ($value === 'any') {
            return self::orFragment(self::flatPatterns(), $col, $paramPrefix);
        }
        if ($value === 'none') {
            [$frag, $params] = self::orFragment(self::flatPatterns(), $col, $paramPrefix);
            if ($frag === '') return ['', []];
            return ["({$col} IS NOT NULL AND {$col} <> '' AND NOT ({$frag}))", $params];
        }
        if (isset(self::ENGINES[$value])) {
            return self::orFragment(self::ENGINES[$value], $col, $paramPrefix);
        }
        return ['', []];
    }

    /**
     * Same filter as sqlFilter(), but evaluates a complex SQL expression once.
     *
     * @return array{0:string, 1:array<string,string>}
     */
    public static function sqlFilterCompact(string $value, string $col, string $paramPrefix = 'se'): array
    {
        if ($value === '') return ['', []];

        $patterns = $value === 'any'
            ? self::flatPatterns()
            : (self::ENGINES[$value] ?? []);
        if ($value === 'none') {
            $patterns = self::flatPatterns();
        }
        if ($patterns === []) return ['', []];

        $binds = [];
        $params = [];
        foreach ($patterns as $i => $pattern) {
            $name = $paramPrefix . '_' . $i;
            $binds[] = ':' . $name;
            $params[$name] = '%' . strtolower($pattern) . '%';
        }
        $match = "LOWER({$col}) LIKE ANY (ARRAY[" . implode(', ', $binds) . ']::text[])';

        if ($value === 'none') {
            return ["({$col} IS NOT NULL AND {$col} <> '' AND NOT ({$match}))", $params];
        }
        return ["({$match})", $params];
    }

    /**
     * OR-fragment matching any of the given engine keys. Unknown keys are
     * skipped; an empty/all-unknown list yields ['', []] so callers can no-op.
     *
     * @param list<string> $engineKeys
     * @return array{0:string, 1:array<string,string>}
     */
    public static function sqlFilterEngines(array $engineKeys, string $col, string $paramPrefix = 'se'): array
    {
        $patterns = [];
        foreach ($engineKeys as $key) {
            foreach (self::ENGINES[$key] ?? [] as $p) {
                $patterns[] = $p;
            }
        }
        return self::orFragment($patterns, $col, $paramPrefix);
    }

    /**
     * @param list<string> $patterns
     * @return array{0:string, 1:array<string,string>}
     */
    private static function orFragment(array $patterns, string $col, string $paramPrefix): array
    {
        if ($patterns === []) return ['', []];
        $parts  = [];
        $params = [];
        foreach ($patterns as $i => $p) {
            $name          = $paramPrefix . '_' . $i;
            $parts[]       = "{$col} ILIKE :{$name}";
            $params[$name] = '%' . $p . '%';
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /** @return list<string> */
    private static function flatPatterns(): array
    {
        $out = [];
        foreach (self::ENGINES as $patterns) {
            foreach ($patterns as $p) $out[] = $p;
        }
        return $out;
    }
}
