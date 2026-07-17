<?php

declare(strict_types=1);

namespace App\Engine;

use Closure;

final class FilterCompiler
{
    /** @var array<string,Closure> */
    private array $cache = [];

    /**
     * @param list<list<array{field:string,op:string,value:mixed}>> $filters
     * @return Closure(Context): bool
     */
    public function compile(array $filters): Closure
    {
        $key = hash('xxh3', json_encode($filters, JSON_THROW_ON_ERROR));
        if (isset($this->cache[$key])) return $this->cache[$key];

        if ($filters === []) {
            return $this->cache[$key] = static fn (Context $_): bool => true;
        }

        $groups = array_map(fn (array $g) => $this->compileGroup($g), $filters);

        return $this->cache[$key] = static function (Context $ctx) use ($groups): bool {
            foreach ($groups as $group) {
                if ($group($ctx)) return true;
            }
            return false;
        };
    }

    /** @param list<array{field:string,op:string,value:mixed}> $group */
    private function compileGroup(array $group): Closure
    {
        $preds = array_map(fn (array $c) => $this->compileCondition($c), $group);
        return static function (Context $ctx) use ($preds): bool {
            foreach ($preds as $p) {
                if (!$p($ctx)) return false;
            }
            return true;
        };
    }

    /** @param array{field:string,op:string,value:mixed} $cond */
    private function compileCondition(array $cond): Closure
    {
        $field = $cond['field'] ?? '';
        $op    = $cond['op'] ?? 'eq';
        $value = $cond['value'] ?? null;

        $extract = self::fieldExtractor($field);

        return match ($op) {
            'eq'      => static fn (Context $ctx): bool => self::eqStrict($extract($ctx), $value),
            'neq'     => static fn (Context $ctx): bool => !self::eqStrict($extract($ctx), $value),
            'in'      => static fn (Context $ctx): bool => self::inList($extract($ctx), $value),
            'not_in'  => static fn (Context $ctx): bool => !self::inList($extract($ctx), $value),
            'regex'   => static fn (Context $ctx): bool => is_string($v = $extract($ctx)) && @preg_match('/' . str_replace('/', '\/', (string)$value) . '/i', $v) === 1,
            'gt'      => static fn (Context $ctx): bool => is_numeric($v = $extract($ctx)) && (float)$v > (float)$value,
            'lt'      => static fn (Context $ctx): bool => is_numeric($v = $extract($ctx)) && (float)$v < (float)$value,
            'between' => static function (Context $ctx) use ($extract, $value): bool {
                $v = $extract($ctx);
                if (!is_numeric($v) || !is_array($value) || count($value) !== 2) return false;
                return (float)$v >= (float)$value[0] && (float)$v <= (float)$value[1];
            },
            'exists'  => static fn (Context $ctx): bool => $extract($ctx) !== null && $extract($ctx) !== '',
            default   => static fn (Context $_): bool => false,
        };
    }

    private static function fieldExtractor(string $field): Closure
    {
        return match ($field) {
            'country'        => static fn (Context $c) => $c->country,
            'region'         => static fn (Context $c) => $c->region,
            'city'           => static fn (Context $c) => $c->city,
            'asn'            => static fn (Context $c) => $c->asn,
            'device'         => static fn (Context $c) => $c->device,
            'os'             => static fn (Context $c) => $c->os,
            'browser'        => static fn (Context $c) => $c->browser,
            'lang'           => static fn (Context $c) => $c->lang,
            'is_bot'         => static fn (Context $c) => $c->isBot ? '1' : '0',
            'bot_name'       => static fn (Context $c) => $c->botName,
            'is_uniq'        => static fn (Context $c) => $c->isUniqVisitor ? '1' : '0',
            'referer'        => static fn (Context $c) => $c->referer,
            'referer_domain' => static fn (Context $c) => $c->refererDomain,
            'utm_source'     => static fn (Context $c) => $c->utm['source']   ?? null,
            'utm_medium'     => static fn (Context $c) => $c->utm['medium']   ?? null,
            'utm_campaign'   => static fn (Context $c) => $c->utm['campaign'] ?? null,
            'utm_term'       => static fn (Context $c) => $c->utm['term']     ?? null,
            'utm_content'    => static fn (Context $c) => $c->utm['content']  ?? null,
            'time_hour'      => static fn (Context $c) => (int)gmdate('G', $c->timestamp),
            'day_of_week'    => static fn (Context $c) => (int)gmdate('w', $c->timestamp),
            'ip_in_list'     => static fn (Context $c) => $c->ip,
            default          => static fn (Context $_) => null,
        };
    }

    private static function eqStrict(mixed $a, mixed $b): bool
    {
        if (is_string($a) && is_string($b)) return strcasecmp($a, $b) === 0;
        return $a == $b; // intentional loose for numeric/string blends
    }

    private static function inList(mixed $needle, mixed $haystack): bool
    {
        if (!is_array($haystack)) {
            $haystack = is_string($haystack) ? array_map('trim', explode(',', $haystack)) : [$haystack];
        }
        foreach ($haystack as $item) {
            if (self::eqStrict($needle, $item)) return true;
        }
        return false;
    }
}
