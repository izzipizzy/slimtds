<?php

declare(strict_types=1);

// Engine may read Admin\Repository (DTOs and query objects) — those are
// thin data accessors. It must NOT depend on the HTTP layer (Controller /
// Middleware / Form / Command), because that layer drives the admin UI.
arch('Engine layer does not depend on Admin HTTP layer')
    ->expect('App\Engine')
    ->not->toUse([
        'App\Admin\Controller',
        'App\Admin\Middleware',
        'App\Admin\Form',
        'App\Admin\Command',
    ]);

arch('Admin controllers use Connection, not PDO directly')
    ->expect('App\Admin\Controller')
    ->not->toUse(PDO::class);

arch('production code avoids dd/var_dump/die')
    ->expect('App')
    ->not->toUse(['dd', 'var_dump', 'die']);

arch('all classes declare strict_types')
    ->expect('App')
    ->toUseStrictTypes();

arch('middleware implements PSR-15')
    ->expect('App\Admin\Middleware')
    ->toImplement(Psr\Http\Server\MiddlewareInterface::class);

arch('controllers live in Controller namespaces')
    ->expect('App\Admin\Controller')
    ->toHaveSuffix('Controller');

arch('repositories live in Repository namespaces')
    ->expect('App\Admin\Repository')
    ->classes()
    ->toHaveSuffix('Repository')
    ->ignoring([
        'App\Admin\Repository\Admin',
        'App\Admin\Repository\Campaign',
        'App\Admin\Repository\Offer',
        'App\Admin\Repository\Flow',
    ]);
