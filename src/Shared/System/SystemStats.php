<?php

declare(strict_types=1);

namespace App\Shared\System;

/**
 * Lightweight host resource snapshot for the admin footer: disk, RAM, load.
 * Read from inside the container — on Linux these reflect the host (overlay
 * filesystem, shared /proc). Every probe degrades to null if unavailable.
 */
final class SystemStats
{
    /**
     * @return array{
     *   disk: array{used:int,free:int,total:int,pct:int}|null,
     *   mem:  array{used:int,total:int,pct:int}|null,
     *   load: array{0:float,1:float,2:float}|null
     * }
     */
    public static function snapshot(string $path = '/'): array
    {
        return ['disk' => self::disk($path), 'mem' => self::mem(), 'load' => self::load()];
    }

    /** @return array{used:int,free:int,total:int,pct:int}|null */
    private static function disk(string $path): ?array
    {
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);
        if (!is_float($total) || !is_float($free) || $total <= 0) {
            return null;
        }
        $used = (int)($total - $free);
        return ['used' => $used, 'free' => (int)$free, 'total' => (int)$total, 'pct' => (int)round($used / $total * 100)];
    }

    /** @return array{used:int,total:int,pct:int}|null */
    private static function mem(): ?array
    {
        $info = @file_get_contents('/proc/meminfo');
        if (!is_string($info) || $info === '') {
            return null;
        }
        $kv = [];
        foreach (explode("\n", $info) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m) === 1) {
                $kv[$m[1]] = (int)$m[2] * 1024;
            }
        }
        $total = $kv['MemTotal'] ?? 0;
        $avail = $kv['MemAvailable'] ?? ($kv['MemFree'] ?? 0);
        if ($total <= 0) {
            return null;
        }
        $used = max(0, $total - $avail);
        return ['used' => $used, 'total' => $total, 'pct' => (int)round($used / $total * 100)];
    }

    /** @return array{0:float,1:float,2:float}|null */
    private static function load(): ?array
    {
        if (!function_exists('sys_getloadavg')) {
            return null;
        }
        $la = @sys_getloadavg();
        if (!is_array($la) || count($la) < 3) {
            return null;
        }
        return [round((float)$la[0], 2), round((float)$la[1], 2), round((float)$la[2], 2)];
    }
}
