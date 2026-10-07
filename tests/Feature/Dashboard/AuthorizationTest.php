<?php

use App\Models\User;
use Tests\Support\Market;

/*
 * Every mutating dashboard endpoint, with a valid payload. Operator mode: guests are refused,
 * users are allowed. Demo mode: anonymous visitors are allowed (rate-limited elsewhere).
 */
dataset('mutations', [
    'kill switch' => ['post', '/api/switches/kill', ['on' => true, 'confirm' => true]],
    'dry run' => ['post', '/api/switches/dry-run', ['on' => true]],
    'pause' => ['post', '/api/products/{id}/pause', []],
    'resume' => ['post', '/api/products/{id}/resume', ['acknowledge' => true]],
    'rule' => ['put', '/api/products/{id}/rule', ['strategy' => 'beat_buybox', 'offset' => 10, 'floor' => 2699, 'ceiling' => 3499, 'min_margin' => 300, 'max_step_pct' => 5, 'cooldown_sec' => 120]],
    'speed' => ['post', '/api/simulator/speed', ['speed' => 10]],
    'running' => ['post', '/api/simulator/running', ['running' => false]],
    'faults' => ['post', '/api/simulator/faults', ['http_429_bps' => 100, 'http_503_bps' => 100]],
    'add bot' => ['post', '/api/simulator/bots', ['asin' => 'B0SIM00001', 'type' => 'matcher']],
    'remove bot' => ['delete', '/api/simulator/bots/B0SIM00001/STEADY-CO', []],
    'stockout' => ['post', '/api/simulator/stockout', ['asin' => 'B0SIM00001', 'seller' => 'STEADY-CO', 'ticks' => 5]],
    'reset' => ['post', '/api/simulator/reset', []],
]);

dataset('reads', [
    ['/api/dashboard'], ['/api/decisions'], ['/api/products/{id}/series'], ['/api/simulator'],
]);

beforeEach(fn () => Market::seed());

/** Datasets use {id}; ids are not stable across tests (sequences survive the rollback). */
function withProductId(string $uri): string
{
    return str_replace('{id}', (string) Market::product()->id, $uri);
}

it('refuses every mutation to a guest in operator mode', function (string $method, string $uri, array $payload) {
    config(['demo.enabled' => false]);
    $this->json($method, withProductId($uri), $payload)->assertForbidden();
})->with('mutations');

it('allows every mutation to a logged-in operator', function (string $method, string $uri, array $payload) {
    config(['demo.enabled' => false]);
    $this->actingAs(User::factory()->create())->json($method, withProductId($uri), $payload)->assertSuccessful();
})->with('mutations');

it('allows every mutation to an anonymous visitor in demo mode', function (string $method, string $uri, array $payload) {
    config(['demo.enabled' => true]);
    $this->json($method, withProductId($uri), $payload)->assertSuccessful();
})->with('mutations');

it('refuses dashboard reads to guests in operator mode and allows them in demo mode', function (string $uri) {
    config(['demo.enabled' => false]);
    $this->getJson(withProductId($uri))->assertForbidden();
    config(['demo.enabled' => true]);
    $this->getJson(withProductId($uri))->assertOk();
})->with('reads');

it('shows guests the welcome page in operator mode and the dashboard in demo mode', function () {
    config(['demo.enabled' => false]);
    $this->get('/')->assertInertia(fn ($page) => $page->component('welcome'));
    config(['demo.enabled' => true]);
    $this->get('/')->assertInertia(fn ($page) => $page->component('repricer/dashboard')->has('initial.products', 5));
});

it('rate-limits mutations per visitor', function () {
    config(['demo.enabled' => true, 'demo.mutations_per_minute' => 3]);
    foreach (range(1, 3) as $_) {
        $this->postJson('/api/switches/dry-run', ['on' => false])->assertOk();
    }
    $this->postJson('/api/switches/dry-run', ['on' => false])->assertStatus(429);
});

it('requires an explicit confirmation for the kill switch and an acknowledgement to resume', function () {
    config(['demo.enabled' => true]);
    $this->postJson('/api/switches/kill', ['on' => true])->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->postJson(withProductId('/api/products/{id}/resume'), [])->assertUnprocessable()->assertJsonValidationErrors('acknowledge');
});

it('caps the demo speed and error injection', function () {
    config(['demo.enabled' => true, 'demo.max_speed' => 50, 'demo.max_fault_bps' => 3000]);
    $this->postJson('/api/simulator/speed', ['speed' => 100])->assertUnprocessable();
    $this->postJson('/api/simulator/faults', ['http_429_bps' => 9000, 'http_503_bps' => 0])->assertUnprocessable();
});

it('never shows a stack trace or SQL to users', function () {
    config(['demo.enabled' => true, 'app.debug' => false]);
    $r = $this->getJson('/api/products/999999/series');
    $r->assertNotFound();
    expect($r->getContent())->not->toContain('SQLSTATE')->not->toContain('#0 ')->not->toContain('vendor/');
});

it('answers a malformed product id with 404, not a database error', function () {
    config(['demo.enabled' => true]);
    $this->getJson('/api/products/abc/series')->assertNotFound();
    $this->postJson('/api/products/1;drop/pause')->assertNotFound();
});
