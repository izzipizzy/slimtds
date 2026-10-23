<?php

declare(strict_types=1);

if (getenv('BROWSER_TESTS') !== '1') {
    test('query editor browser tests skipped', function () {})->skip('set BROWSER_TESTS=1');
    return;
}

beforeEach(function (): void {
    browserAdmin('query-editor-pass');
    $pdo = browserPdo();
    $pdo->exec("DELETE FROM core.campaigns WHERE slug = 'query-browser'");
    $pdo->exec("INSERT INTO core.campaigns (name, slug) VALUES ('Ember', 'query-browser')");
    $this->cid = $pdo->query("SELECT id FROM core.campaigns WHERE slug = 'query-browser'")->fetchColumn();
});

afterEach(function (): void {
    browserPdo()->exec("DELETE FROM core.campaigns WHERE slug = 'query-browser'");
});

test('named query editor saves reopens and switches existing country in RU and EN', function (string $lang, string $name, string $label, string $nameLabel): void {
    $page = visit(browserUrl('/admin/login'), ['locale' => $lang === 'en' ? 'en-US' : 'ru-RU']);
    $page->fill('login', 'admin')->fill('password', 'query-editor-pass')->press('button[type=submit]');
    $page->navigate(browserUrl('/admin/campaigns/' . $this->cid . '/flows/new?lang=' . $lang));
    expect($page->script('Alpine.$data(document.querySelector("[x-data^=flowBuilder]")).filters'))->toBe([]);
    expect($page->script('document.querySelector("[name=name]").validity.valueMissing'))->toBeTrue();
    expect($page->script('document.querySelector("button[type=submit]").disabled'))->toBeFalse();
    $page->fill('name', 'Ember')->select('target_type', 'none');
    $page->click('button[\@click="addGroup()"]');
    $page->select('select[data-filter-field]', 'param:');
    $page->assertVisible('select[data-filter-field]')->assertVisible('input[data-param-name]');
    expect($page->script('document.querySelector("select[data-filter-field]").selectedOptions[0].textContent'))->toBe($label);
    expect($page->script('document.querySelector("input[data-param-name]").getAttribute("aria-label")'))->toBe($nameLabel);
    $page->fill('input[data-param-name]', $name)->select('select[x-model="cond.op"]', 'eq')->fill('input[x-model="cond.value"]', 'ember');
    $page->press('button[type=submit]');
    $pdo = browserPdo();
    $stmt = $pdo->prepare('SELECT id, filters FROM core.flows WHERE campaign_id = ?');
    $stmt->execute([$this->cid]);
    $flow = $stmt->fetch(PDO::FETCH_ASSOC);
    expect(json_decode($flow['filters'], true))->toEqual([[['field' => 'param:' . $name, 'op' => 'eq', 'value' => 'ember']]]);
    $edit = '/admin/campaigns/' . $this->cid . '/flows/' . $flow['id'] . '/edit?lang=' . $lang;
    $page->navigate(browserUrl($edit))->assertValue('select[data-filter-field]', 'param:')->assertValue('input[data-param-name]', $name)
        ->assertValue('select[x-model="cond.op"]', 'eq')->assertValue('input[x-model="cond.value"]', 'ember');
    $page->select('select[data-filter-field]', 'country')->fill('input[x-model="cond.value"]', 'us')->press('button[type=submit]');
    $page->navigate(browserUrl($edit))->assertValue('select[data-filter-field]', 'country')->assertValue('input[x-model="cond.value"]', 'us');
    $stmt->execute([$this->cid]);
    expect(json_decode($stmt->fetch(PDO::FETCH_ASSOC)['filters'], true))->toEqual([[['field' => 'country', 'op' => 'eq', 'value' => 'us']]]);
})->with([['en', 'brand', 'Query parameter', 'Parameter name'], ['ru', 'sub_id_30', 'Параметр запроса', 'Имя параметра']]);

test('invalid query name is retained safely after server rejection', function (): void {
    $page = visit(browserUrl('/admin/login'), ['locale' => 'en-US']);
    $page->fill('login', 'admin')->fill('password', 'query-editor-pass')->press('button[type=submit]');
    $page->navigate(browserUrl('/admin/campaigns/' . $this->cid . '/flows/new?lang=en'))->fill('name', 'Ember')->select('target_type', 'none');
    $page->click('button[\@click="addGroup()"]')->select('select[data-filter-field]', 'param:');
    $name = '<img src=x onerror=alert(1)>';
    $page->fill('input[data-param-name]', $name)->select('select[x-model="cond.op"]', 'neq')->fill('input[x-model="cond.value"]', 'cinder')->press('button[type=submit]');
    $page->assertValue('input[data-param-name]', $name)->assertValue('select[x-model="cond.op"]', 'neq')->assertValue('input[x-model="cond.value"]', 'cinder')->assertVisible('.form-error');
    expect($page->script('document.querySelectorAll("img[src=x]").length'))->toBe(0);
    $stmt = browserPdo()->prepare('SELECT count(*) FROM core.flows WHERE campaign_id = ?');
    $stmt->execute([$this->cid]);
    expect((int)$stmt->fetchColumn())->toBe(0);
});
