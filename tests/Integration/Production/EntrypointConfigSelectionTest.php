<?php

declare(strict_types=1);

/*
 * The image CMD used to carry a hardcoded --config, which landed after the one
 * entrypoint.sh adds for the active DEPLOY_MODE and won — so every production
 * mode silently ran the dev Caddyfile for months. Nothing caught it because
 * nothing ever asserted which config the container would actually start with.
 *
 * This runs the real entrypoint with a stub on PATH in place of frankenphp, so
 * the assertion is about the argv the container would exec, not about the text
 * of the Dockerfile.
 */

/** Run docker/entrypoint.sh with a fake frankenphp and return [argv, exitCode]. */
function runEntrypoint(string $mode, string ...$extraArgs): array
{
    $repoRoot = dirname(__DIR__, 3);
    $stubDir = sys_get_temp_dir() . '/slimtds-entrypoint-stub-' . getmypid();
    if (!is_dir($stubDir)) {
        mkdir($stubDir, 0o777, true);
    }
    $stub = $stubDir . '/frankenphp';
    file_put_contents($stub, "#!/bin/sh\nprintf '%s\\n' \"\$*\"\n");
    chmod($stub, 0o755);

    $command = array_merge(['sh', $repoRoot . '/docker/entrypoint.sh', 'frankenphp', 'run'], $extraArgs);

    try {
        $proc = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repoRoot,
            array_merge(getenv(), [
                'DEPLOY_MODE' => $mode,
                'PATH' => $stubDir . ':' . (getenv('PATH') ?: '/usr/bin:/bin'),
            ]),
        );

        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start entrypoint.sh');
        }

        $stdout = trim((string)stream_get_contents($pipes[1]));
        $stderr = trim((string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
    } finally {
        @unlink($stub);
        @rmdir($stubDir);
    }

    return [$stdout !== '' ? $stdout : $stderr, $exitCode];
}

test('each deploy mode starts with exactly one Caddyfile, and it is its own', function (string $mode, string $expected): void {
    [$argv, $exitCode] = runEntrypoint($mode);

    expect($exitCode)->toBe(0, "entrypoint refused DEPLOY_MODE={$mode}: {$argv}");
    expect(substr_count($argv, '--config'))->toBe(
        1,
        "DEPLOY_MODE={$mode} produced more than one --config, so the last one silently wins: {$argv}",
    );
    expect($argv)->toContain($expected);
})->with([
    'dev'     => ['dev', '/app/config/frankenphp/Caddyfile.dev'],
    'cf_flex' => ['cf_flex', '/app/config/frankenphp/Caddyfile.cf'],
    'direct'  => ['direct', '/app/config/frankenphp/Caddyfile.direct'],
]);

test('a --config in the command is refused rather than silently preferred', function (string $flag): void {
    [$message, $exitCode] = runEntrypoint('cf_flex', ...explode(' ', $flag));

    expect($exitCode)->toBe(64, "expected EX_USAGE for '{$flag}', got: {$message}");
    expect($message)->toContain('refusing to start');
})->with([
    'long form'          => '--config /elsewhere',
    'long form glued'    => '--config=/elsewhere',
    'short form'         => '-c /elsewhere',
    'short form glued'   => '-c/elsewhere',
    'resume'             => '--resume',
    'resume short'       => '-r',
    'inside a cluster'   => '-er',
]);

test('flags that do not touch the config are left alone', function (string $flag): void {
    [$argv, $exitCode] = runEntrypoint('cf_flex', ...explode(' ', $flag));

    expect($exitCode)->toBe(0, "entrypoint wrongly refused '{$flag}': {$argv}");
    expect($argv)->toContain('/app/config/frankenphp/Caddyfile.cf');
})->with([
    'adapter'                 => '--adapter caddyfile',
    'adapter short glued'     => '-acaddyfile',
    'adapter in a cluster'    => '-eacaddyfile',
    'a path that starts as a flag would' => '--pidfile -caddy.pid',
    'after the end-of-flags marker'      => '-- -c',
]);

test('cf_full refuses to start while it would serve a plaintext origin', function (): void {
    // Named after what it promises rather than what it does: no tls directive,
    // no certificate mounted, so :443 would be plain HTTP behind Cloudflare Full.
    [$message, $exitCode] = runEntrypoint('cf_full');

    expect($exitCode)->toBe(78);
    expect($message)->toContain('does not do what its');
});

test('an unknown deploy mode refuses to start', function (): void {
    [$message, $exitCode] = runEntrypoint('not-a-mode');

    expect($exitCode)->toBe(2);
    expect($message)->toContain('unknown DEPLOY_MODE');
});

test('the image hands entrypoint one command and no Caddyfile of its own', function (): void {
    // This is where the bug lived: a --config baked into CMD arrives after the
    // one entrypoint.sh adds and wins, so the mode-selected Caddyfile is chosen
    // and then quietly discarded. The tests above stub a clean `frankenphp run`,
    // so only this one notices it coming back.
    //
    // Asserted as exact argv rather than "does not contain --config": a second
    // CMD silently replaces the first, a shell-form CMD hides its flags inside
    // one string, and a substring check for '-c' matches innocent text. Pinning
    // the whole vector leaves nowhere for a flag to hide.
    $dockerfile = (string)file_get_contents(dirname(__DIR__, 3) . '/docker/Dockerfile');
    // Join backslash continuations so a directive split over lines reads as one.
    $dockerfile = preg_replace('/\\\\\s*\R\s*/', ' ', $dockerfile) ?? $dockerfile;
    // Only the final stage ships. An earlier build stage may carry its own CMD
    // or ENTRYPOINT without ever reaching the runtime image.
    $stages = preg_split('/^\s*FROM\s+/mi', $dockerfile) ?: [];
    $dockerfile = (string)end($stages);

    $directives = static function (string $keyword) use ($dockerfile): array {
        preg_match_all('/^\s*' . $keyword . '\s+(.*)$/mi', $dockerfile, $m);

        return array_map(trim(...), $m[1]);
    };

    $cmds = $directives('CMD');
    $entrypoints = $directives('ENTRYPOINT');

    expect($cmds)->toHaveCount(1, 'Docker keeps only the last CMD; a second one silently replaces it');
    expect($entrypoints)->toHaveCount(1, 'Docker keeps only the last ENTRYPOINT');

    expect(json_decode($cmds[0], true))->toBe(
        ['frankenphp', 'run'],
        'CMD must hand entrypoint.sh a bare run: any flag here is appended after '
        . "entrypoint's own --config and wins. Got: {$cmds[0]}",
    );
    expect(json_decode($entrypoints[0], true))->toBe(['/usr/local/bin/entrypoint.sh']);

    // FRANKENPHP_CONFIG holds Caddy directives upstream, not a path; a value here
    // means someone tried to steer the config through the environment instead.
    expect($dockerfile)->not->toMatch('/^\s*ENV\s+FRANKENPHP_CONFIG/mi');
});
