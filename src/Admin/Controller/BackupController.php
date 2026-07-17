<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Process\Process;

/**
 * Backup mutations (create / download / delete). The listing lives inside
 * /admin/settings as a tab — see SettingsController.
 */
final class BackupController
{
    // Same path the cron's db:backup writes to. Mounted as ./var/backups on host.
    private const BACKUPS_DIR = '/app/var/backups';

    /**
     * Run pg_dump synchronously and redirect back. Same env/flags as the
     * scheduled `db:backup` cron — keeping the logic in one place by shelling
     * to the console command would also work, but a direct Process call here
     * lets us surface stderr in flash messages without console wrapping.
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $dir = self::BACKUPS_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            flash_push('error', "Cannot create backups directory: {$dir}");
            return $response->withHeader('Location', '/admin/settings#backups')->withStatus(302);
        }

        $ts       = date('Y-m-d_H-i-s');
        $dumpFile = "{$dir}/{$ts}.dump";

        $env = [
            'PGHOST'     => $_ENV['DB_HOST']     ?? 'db',
            'PGPORT'     => $_ENV['DB_PORT']     ?? '5432',
            'PGDATABASE' => $_ENV['DB_NAME']     ?? 'slimtds',
            'PGUSER'     => $_ENV['DB_USER']     ?? 'slimtds',
            'PGPASSWORD' => $_ENV['DB_PASSWORD'] ?? 'slimtds',
        ];

        $proc = new Process(
            ['/usr/bin/pg_dump', '--format=custom', "--file={$dumpFile}"],
            null,
            $env,
        );
        $proc->setTimeout(600);
        $proc->run();

        if (!$proc->isSuccessful()) {
            if (file_exists($dumpFile)) unlink($dumpFile);
            $err = trim($proc->getErrorOutput()) ?: ('pg_dump exit ' . (int)$proc->getExitCode());
            flash_push('error', 'Backup failed: ' . $err);
            return $response->withHeader('Location', '/admin/settings#backups')->withStatus(302);
        }

        $size = (int)(filesize($dumpFile) ?: 0);
        flash_push('success', sprintf('Backup created: %s (%.1f KB)', basename($dumpFile), $size / 1024));
        return $response->withHeader('Location', '/admin/settings#backups')->withStatus(302);
    }

    /**
     * Stream a dump file to the browser. Whitelists by basename match against
     * the directory listing so a forged path can't escape the backups dir.
     */
    public function download(ServerRequestInterface $request, ResponseInterface $response, string $name): ResponseInterface
    {
        $base = basename($name);
        $path = self::BACKUPS_DIR . '/' . $base;

        if (!preg_match('/^[A-Za-z0-9_\-]+\.dump$/', $base) || !is_file($path)) {
            return $response->withStatus(404);
        }

        $size = (int)(filesize($path) ?: 0);
        $body = $response->getBody();
        $fh = fopen($path, 'rb');
        if ($fh === false) return $response->withStatus(500);
        while (!feof($fh)) {
            $chunk = fread($fh, 65536);
            if ($chunk === false) break;
            $body->write($chunk);
        }
        fclose($fh);

        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $base . '"')
            ->withHeader('Content-Length', (string)$size);
    }

    /**
     * Delete a single dump. Same whitelist as download().
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, string $name): ResponseInterface
    {
        $base = basename($name);
        $path = self::BACKUPS_DIR . '/' . $base;

        if (!preg_match('/^[A-Za-z0-9_\-]+\.dump$/', $base) || !is_file($path)) {
            flash_push('error', 'Backup not found');
            return $response->withHeader('Location', '/admin/settings#backups')->withStatus(302);
        }

        if (@unlink($path)) {
            flash_push('success', "Deleted {$base}");
        } else {
            flash_push('error', "Failed to delete {$base}");
        }
        return $response->withHeader('Location', '/admin/settings#backups')->withStatus(302);
    }

}
