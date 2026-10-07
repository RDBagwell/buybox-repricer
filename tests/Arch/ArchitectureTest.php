<?php

use App\Simulator\Persistence\WorldRepository;

/*
 * The boundaries from the README, enforced.
 */

arch('the repricer never imports the simulator (only MarketServiceProvider binds the two)')
    ->expect('App\Repricer')
    ->not->toUse('App\Simulator');

arch('the rules namespace is pure: no framework, models, HTTP, facades or I/O')
    ->expect('App\Repricer\Rules')
    ->not->toUse([
        'Illuminate',
        'Carbon',
        'App\Repricer\Models',
        'App\Repricer\Market',
        'App\Repricer\Jobs',
        'App\Simulator',
        'GuzzleHttp',
        'Psr\Http',
    ]);

arch('the rules namespace never reads a clock or randomness')
    ->expect('App\Repricer\Rules')
    ->not->toUse(['now', 'today', 'time', 'microtime', 'hrtime', 'date', 'mktime', 'strtotime', 'sleep', 'usleep', 'rand', 'mt_rand', 'random_int', 'uniqid', 'Random\Randomizer']);

arch('the rules namespace does no I/O')
    ->expect('App\Repricer\Rules')
    ->not->toUse(['file_get_contents', 'file_put_contents', 'fopen', 'curl_init', 'curl_exec', 'error_log', 'dump', 'dd', 'logger', 'info', 'report', 'event', 'dispatch', 'config', 'env', 'app', 'resolve', 'cache']);

arch('rules implement the Rule interface')
    ->expect('App\Repricer\Rules\Rules')
    ->classes()
    ->toImplement('App\Repricer\Rules\Rule')
    ->ignoring('App\Repricer\Rules\Rules\NoCompetition');

arch('the simulator engine is deterministic: no framework, wall clock or global randomness')
    ->expect('App\Simulator\Engine')
    ->not->toUse(['Illuminate', 'Carbon', 'App\Repricer', 'now', 'time', 'microtime', 'hrtime', 'date', 'rand', 'mt_rand', 'random_int', 'uniqid', 'Illuminate\Support\Str']);

arch('only the simulator adapter knows the repricer, and only its market contract')
    ->expect(['App\Simulator\Engine', 'App\Simulator\Persistence', 'App\Simulator\Delivery', 'App\Simulator\Console', 'App\Simulator\SimulationRunner'])
    ->not->toUse('App\Repricer');

arch('the simulator adapter uses the repricer market contract and nothing else of the repricer')
    ->expect('App\Simulator\Adapter')
    ->not->toUse(['App\Repricer\Models', 'App\Repricer\Rules', 'App\Repricer\Pricing', 'App\Repricer\Jobs', 'App\Repricer\Outbound', 'App\Repricer\Settings']);

arch('shared support code depends on neither side')
    ->expect('App\Support')
    ->not->toUse(['App\Repricer', 'App\Simulator']);

arch('money is never a float')
    ->expect('App')
    ->not->toUse(['floatval', 'round', 'number_format', 'floor', 'ceil'])
    ->ignoring(['App\Repricer\Jobs\PushPriceJob', 'App\Simulator\Console\SimRunCommand']); // ceil on ms→s queue delay; CLI display only

const REPRICER_TABLES = ['products', 'pricing_rules', 'price_decisions', 'offer_snapshots', 'price_pushes', 'buybox_history', 'settings', 'duplicate_deliveries'];

/** @return array<string, string> path => source */
function sources(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $out;
}

it('keeps the simulator on its own sim_ tables', function () {
    $ref = new ReflectionClass(WorldRepository::class);
    foreach ($ref->getConstants() as $name => $table) {
        expect($table)->toStartWith('sim_', "WorldRepository::{$name}");
    }

    foreach (sources('app/Simulator') as $path => $code) {
        preg_match_all("/(?:table|from|join)\(\s*['\"]([a-z_]+)['\"]/", $code, $m);
        foreach ($m[1] as $table) {
            expect($table)->toStartWith('sim_', "{$path} touches {$table}");
        }
        foreach (REPRICER_TABLES as $table) {
            expect($code)->not->toMatch("/['\"]{$table}['\"]/", "{$path} mentions repricer table {$table}");
        }
    }
});

it('keeps the repricer off the simulator tables', function () {
    foreach (sources('app/Repricer') as $path => $code) {
        expect($code)->not->toContain("'sim_", $path);
    }
});

it('stores money as integer columns only', function () {
    foreach (sources('database/migrations') as $path => $code) {
        expect($code)->not->toMatch('/->(float|double|decimal|unsignedDecimal)\(/', $path);
    }
});
