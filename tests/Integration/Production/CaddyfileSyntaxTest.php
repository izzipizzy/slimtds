<?php

declare(strict_types=1);

/*
 * The production Caddyfiles are only ever read by one thing: the FrankenPHP
 * binary in the runtime image. Asserting on their text says nothing about
 * whether that binary accepts them — a file can hold the exact expected
 * substring and still fail to parse a line below it, which is how these two
 * shipped broken. So hand the real file to the real parser.
 *
 * Inside the app container that binary is on PATH. On a bare CI runner it is
 * not, and a test that quietly skips there would leave the regression it
 * exists to catch unguarded — so fall back to the image the runtime is built
 * from. The tag is read out of docker/Dockerfile rather than repeated here,
 * so the parser under test cannot drift from the one production runs.
 */

test('production Caddyfiles are accepted by the runtime that serves them', function (string $relativePath): void {
    $repoRoot = dirname(__DIR__, 3);

    // Both files interpolate placeholders. Left empty, the site address is
    // empty too and the parse fails for the wrong reason.
    $placeholders = ['CF_LISTEN_PORT' => '80', 'DOMAIN' => 'tds.example.com'];

    if (trim((string)shell_exec('command -v frankenphp 2>/dev/null')) !== '') {
        $command = ['frankenphp', 'adapt', '--config', $repoRoot . '/' . $relativePath, '--adapter', 'caddyfile'];
    } elseif (trim((string)shell_exec('command -v docker 2>/dev/null')) !== '') {
        $dockerfile = (string)file_get_contents($repoRoot . '/docker/Dockerfile');

        if (preg_match('/^FROM\s+(dunglas\/frankenphp:\S+)/mi', $dockerfile, $matches) !== 1) {
            $this->markTestSkipped('no dunglas/frankenphp image tag found in docker/Dockerfile');
        }

        $command = [
            'docker', 'run', '--rm',
            '--volume', $repoRoot . ':/repo:ro',
            '--workdir', '/repo',
            '--env', 'CF_LISTEN_PORT=' . $placeholders['CF_LISTEN_PORT'],
            '--env', 'DOMAIN=' . $placeholders['DOMAIN'],
            '--entrypoint', 'frankenphp',
            $matches[1],
            'adapt', '--config', $relativePath, '--adapter', 'caddyfile',
        ];
    } else {
        $this->markTestSkipped('neither frankenphp nor docker is available to parse the file');
    }

    $proc = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        array_merge(getenv(), $placeholders),
    );

    if (!is_resource($proc)) {
        throw new RuntimeException('cannot start ' . $command[0]);
    }

    stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    // `adapt` reports the offending line on stderr; carry it into the failure
    // so a broken file names its own problem instead of just "expected 0".
    $complaints = array_filter(
        explode(PHP_EOL, $stderr),
        static fn (string $line): bool => str_contains($line, '"level":"error"') || str_contains($line, 'Error:'),
    );

    expect($exitCode)->toBe(0, $relativePath . ' — ' . implode(PHP_EOL, $complaints));
})->with([
    'Cloudflare production mode' => 'config/frankenphp/Caddyfile.cf',
    'direct production mode' => 'config/frankenphp/Caddyfile.direct',
]);
