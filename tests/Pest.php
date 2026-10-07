<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| tests/Unit is framework-free (pure rules and simulator engine).
| Feature, Contract and Simulation tests boot the app against Postgres and
| Redis; phpunit.xml points them at dedicated test databases, which are
| flushed before every test.
|
*/

pest()->extend(TestCase::class)->in('Arch');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Redis::connection()->command('flushdb');
        Redis::connection('cache')->command('flushdb');
    })
    ->in('Feature/Simulator', 'Feature/Repricer', 'Contract', 'Simulation');
