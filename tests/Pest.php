<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Unit tests under tests/Unit are framework-free. Feature, contract and
| simulation tests boot the application.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Contract', 'Simulation');
