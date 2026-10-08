<?php

namespace App\Demo;

use App\Simulator\Engine\Bots\BotRegistry;
use App\Simulator\Engine\SimOffer;
use App\Simulator\Persistence\WorldRepository;

/**
 * What the dashboard's simulator panel shows: run state, speed, injected error rates, and each
 * listing's offers (who is a bot, who is in stock, who holds the Buy Box).
 */
final class SimulatorPanel
{
    public function __construct(
        private readonly WorldRepository $worlds,
        private readonly BotRegistry $bots,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function state(): ?array
    {
        if (! $this->worlds->exists()) {
            return null;
        }

        $world = $this->worlds->load();
        $ours = (string) config('market.seller_id');

        return [
            'controls' => $this->worlds->controls(),
            'tick' => $world->tick,
            'tick_seconds' => (int) config('simulator.tick_seconds'),
            'bot_types' => $this->bots->keys(),
            'limits' => [
                'max_speed' => config('demo.enabled') ? (int) config('demo.max_speed') : 100,
                'max_offers_per_listing' => (int) config('demo.max_offers_per_listing'),
                'max_fault_bps' => (int) config('demo.max_fault_bps'),
            ],
            'listings' => array_values(array_map(fn ($l) => [
                'asin' => $l->asin,
                'title' => $l->title,
                'model' => $l->model,
                'buybox' => $l->buyBoxSellerId === $ours ? 'ours' : $l->buyBoxSellerId,
                'offers' => array_values(array_map(fn (SimOffer $o) => [
                    'seller' => $o->sellerId === $ours ? 'ours' : $o->sellerId,
                    'bot' => $o->bot,
                    'price' => $o->price->cents,
                    'shipping' => $o->shipping->cents,
                    'in_stock' => $o->inStock,
                ], $l->offers)),
            ], $world->listings)),
        ];
    }
}
