<?php

declare(strict_types=1);

/**
 * HTML forms cannot nest: the parser drops an inner <form> tag and lets the
 * first inner </form> close the OUTER form. Every control in between — hidden
 * CSRF fields included — then belongs to the outer form. On a GET filter bar
 * that put the CSRF token into the URL (history, Referer, access logs) on every
 * "apply", and turned the inner form's submit button into a filter submit.
 */
function formTags(string $html): array
{
    preg_match_all('#<(/?)form\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE);
    return array_map(static fn (array $tag, array $slash): array => [$slash[0] === '/' ? 'close' : 'open', $tag[1]], $m[0], $m[1]);
}

test('no admin template opens a form inside another form', function (string $file): void {
    $src = (string)file_get_contents($file);
    $depth = 0;
    foreach (formTags($src) as [$kind, $offset]) {
        $depth += $kind === 'open' ? 1 : -1;
        $line = substr_count($src, "\n", 0, $offset) + 1;
        expect($depth)->toBeLessThanOrEqual(1, "nested <form> at {$file}:{$line}")
            ->and($depth)->toBeGreaterThanOrEqual(0, "unbalanced </form> at {$file}:{$line}");
    }
    expect($depth)->toBe(0, "unclosed <form> in {$file}");
})->with(function (): array {
    $root = dirname(__DIR__, 3) . '/resources/views';
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php' && str_contains((string)file_get_contents($f->getPathname()), '<form')) {
            $files[substr($f->getPathname(), strlen($root) + 1)] = [$f->getPathname()];
        }
    }
    ksort($files);
    return $files;
});

test('the clicks filter form submits no CSRF token, and the column buttons reach their own forms', function (): void {
    $root = dirname(__DIR__, 3);
    $view = new \App\Shared\View\View(
        $root . '/resources/views',
        new \App\Shared\Asset\Manifest($root . '/public/assets/manifest.json'),
        new \App\Shared\I18n\I18n((new \App\Shared\I18n\TranslatorFactory($root . '/resources/translations'))->create()),
    );
    $html = $view->render('admin/clicks/index', [
        'title' => 'Clicks', 'items' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'filters' => [],
        'timeline' => [], 'campaigns' => [], 'columns_meta' => \App\Admin\Clicks\ColumnPreferences::COLUMNS,
        'visible_columns' => ['referer'], 'sort' => ['field' => 'created_at', 'dir' => 'desc'],
        'visitor' => null, 'lang' => 'en', 'csrf_token' => 'deadbeef', '__layout__' => null,
    ]);

    // The GET form, as the browser delimits it: opening tag to the first </form>.
    $start = (int)strpos($html, '<form method="get"');
    $getForm = substr($html, $start, (int)strpos($html, '</form>', $start) - $start);

    expect($getForm)->not->toContain('deadbeef')
        ->and($getForm)->not->toContain('name="_csrf"')
        // Controls that live inside it but belong elsewhere say so explicitly.
        ->and($getForm)->toContain('form="clicks-columns-reset-form"')
        ->and($getForm)->toContain('name="columns" form="clicks-columns-save-form"')
        ->and($html)->toMatch('#<form id="clicks-columns-save-form" method="post"[^>]*>\s*<input type="hidden" name="_csrf" value="deadbeef"#')
        ->and($html)->toMatch('#<form id="clicks-columns-reset-form" method="post"[^>]*>\s*<input type="hidden" name="_csrf" value="deadbeef"#');
});
