<?php

declare(strict_types=1);

use DI\Bridge\Slim\Bridge as SlimBridge;
use Slim\App;

return static function (): App {
    $container = (require __DIR__ . '/di.php')();
    $app = SlimBridge::create($container);

    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    $app->addErrorMiddleware(
        displayErrorDetails: filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOL),
        logErrors: true,
        logErrorDetails: true,
    );

    (require __DIR__ . '/routes.php')($app);

    return $app;
};
