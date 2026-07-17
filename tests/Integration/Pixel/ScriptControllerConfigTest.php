<?php

declare(strict_types=1);

use App\Admin\Repository\SettingsRepository;
use App\Pixel\ScriptController;
use App\Shared\Db\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

beforeEach(function (): void {
    $this->repo = new SettingsRepository(new Connection(pdo()));
    $this->repo->set('rrweb_sample_rate', '40');
    $this->tmp = tempnam(sys_get_temp_dir(), 'pjs');
    file_put_contents($this->tmp, 'console.log("pixel")');
    $this->ctrl = new ScriptController($this->tmp, $this->repo);
});

afterEach(function (): void {
    @unlink($this->tmp);
});

test('served p.js is prefixed with window.__slim rate config', function (): void {
    $req = (new ServerRequestFactory())->createServerRequest('GET', '/p.js');
    $resp = ($this->ctrl)($req, new Response());
    $body = (string)$resp->getBody();
    expect($body)->toContain('window.__slim=');
    expect($body)->toContain('"rate":40');
    expect($body)->toContain('console.log("pixel")');

    $expected = 'window.__slim=' . json_encode(['rate' => 40]) . ";\n" . 'console.log("pixel")';
    expect($resp->getHeaderLine('ETag'))->toBe('"' . md5($expected) . '"');
});
