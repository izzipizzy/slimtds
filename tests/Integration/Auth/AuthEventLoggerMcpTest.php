<?php

declare(strict_types=1);

use App\Shared\Auth\AuthEventLogger;
use App\Shared\Db\Connection;

test('mcp key events are accepted by the logger and by the table', function (string $event): void {
    $db = new Connection(pdo());
    $db->execute("DELETE FROM core.auth_events WHERE event_type LIKE 'mcp_%'");
    (new AuthEventLogger($db))->log($event, 'admin', '10.0.0.1', 'pest', ['prefix' => 'stds_abcd']);
    expect((int)$db->fetchScalar('SELECT count(*) FROM core.auth_events WHERE event_type = :e', ['e' => $event]))->toBe(1);
})->with(['mcp_key_generated', 'mcp_key_revoked']);
