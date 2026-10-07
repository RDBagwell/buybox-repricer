<?php

use App\Repricer\Market\MarketAdapter;
use App\Simulator\SimulationRunner;
use Tests\Contract\AdapterHarness;
use Tests\Contract\MarketAdapterContract;
use Tests\Support\Adapters;
use Tests\Support\Market;

beforeEach(fn () => Market::seed());

MarketAdapterContract::register(fn () => new class implements AdapterHarness
{
    private ?MarketAdapter $adapter = null;

    public function adapter(): MarketAdapter
    {
        return $this->adapter ??= Adapters::simulator();
    }

    public function knownAsin(): string
    {
        return 'B0SIM00004';
    }

    public function ourSku(): string
    {
        return 'BRD-BAM-L';
    }

    public function ourSellerId(): string
    {
        return (string) config('market.seller_id');
    }

    public function triggerOfferChange(): void
    {
        $runner = app(SimulationRunner::class);
        for ($i = 0; $i < 200; $i++) {
            foreach ($runner->tick()->events as $e) {
                if ($e->asin === $this->knownAsin()) {
                    return;
                }
            }
        }
        throw new RuntimeException('the simulation produced no offer change');
    }
});
