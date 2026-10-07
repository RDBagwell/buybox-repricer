<?php

namespace App\Simulator;

use App\Simulator\Delivery\NotificationPublisher;
use App\Simulator\Engine\AnyOfferChanged;
use App\Simulator\Engine\Bots\BotRegistry;
use App\Simulator\Engine\Listing;
use App\Simulator\Engine\SimOffer;
use App\Simulator\Engine\Simulation;
use App\Simulator\Persistence\WorldRepository;
use App\Support\Fulfillment;
use App\Support\Money;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Operator controls over the running world: add or remove bots, force a stockout, and change
 * speed, running state and injected API error rates. Every change to a listing recomputes its
 * Buy Box and publishes an AnyOfferChanged, exactly as a tick would.
 */
final class SimulatorControl
{
    public function __construct(
        private readonly WorldRepository $worlds,
        private readonly Simulation $simulation,
        private readonly NotificationPublisher $publisher,
        private readonly BotRegistry $bots,
        private readonly int $maxOffersPerListing = 6,
    ) {}

    public function addBot(string $asin, string $type): SimOffer
    {
        if (! in_array($type, $this->bots->keys(), true)) {
            throw new InvalidArgumentException("Unknown bot [{$type}].");
        }

        return $this->mutate($asin, function (Listing $listing) use ($type): SimOffer {
            if (count($listing->offers) >= $this->maxOffersPerListing) {
                throw new InvalidArgumentException("A listing can have at most {$this->maxOffersPerListing} offers.");
            }

            $reference = $listing->buyBoxOffer()?->landed() ?? Money::min(...array_map(fn (SimOffer $o) => $o->landed(), array_values($listing->offers)));
            $n = 1;
            while ($listing->offer(strtoupper($type).'-'.$n) !== null) {
                $n++;
            }

            $offer = new SimOffer(
                strtoupper($type).'-'.$n,
                $reference->plus(Money::cents(50)),
                Money::zero(),
                Fulfillment::Merchant,
                93,
                2,
                null,
                $type,
                $this->defaultParams($type, $reference),
            );
            $listing->put($offer);

            return $offer;
        }, 'competitor_added');
    }

    public function removeBot(string $asin, string $sellerId): void
    {
        $this->mutate($asin, function (Listing $listing) use ($sellerId): void {
            $offer = $listing->offer($sellerId);
            if ($offer === null || $offer->bot === null) {
                throw new InvalidArgumentException("No bot {$sellerId} on {$listing->asin}.");
            }
            unset($listing->offers[$sellerId]);
            $this->worlds->removeOffer($listing->asin, $sellerId);
        }, 'competitor_removed');
    }

    public function stockout(string $asin, string $sellerId, int $ticks): void
    {
        $this->mutate($asin, function (Listing $listing, int $tick) use ($sellerId, $ticks): void {
            $offer = $listing->offer($sellerId);
            if ($offer === null || $offer->bot === null) {
                throw new InvalidArgumentException("No bot {$sellerId} on {$listing->asin}.");
            }
            $listing->put($this->simulation->outOfStock($offer, $tick + max(1, $ticks)));
        }, 'competitor_stock');
    }

    public function setSpeed(int $speed): void
    {
        $this->worlds->updateControls(['speed' => max(1, min(100, $speed))]);
    }

    public function setRunning(bool $running): void
    {
        $this->worlds->updateControls(['running' => $running]);
    }

    public function setFaults(int $http429Bps, int $http503Bps): void
    {
        $this->worlds->updateControls([
            'fault_429_bps' => max(0, min(5000, $http429Bps)),
            'fault_503_bps' => max(0, min(5000, $http503Bps)),
        ]);
    }

    /**
     * @template T
     *
     * @param  \Closure(Listing, int): T  $change
     * @return T
     */
    private function mutate(string $asin, \Closure $change, string $changeType): mixed
    {
        /** @var array{0: T, 1: AnyOfferChanged} $done */
        $done = $this->worlds->db()->transaction(function () use ($asin, $change, $changeType) {
            $world = $this->worlds->load(lock: true);
            $listing = $world->listing($asin) ?? throw new InvalidArgumentException("Unknown listing {$asin}.");

            $result = $change($listing, $world->tick);
            foreach ($listing->offers as $offer) {
                if ($offer->bot !== null) {
                    $this->worlds->putOffer($asin, $offer);
                }
            }
            $this->simulation->recomputeBuyBox($listing, $world->tick, $world->clock->nowMs);
            $this->worlds->saveBuyBox($listing);

            $event = AnyOfferChanged::fromListing($listing, $world->tick, $world->clock->nowMs, 'operator', $changeType)
                ->withEventId((string) Str::uuid7());
            $this->worlds->recordEvent($event, $this->worlds->currentRun());

            return [$result, $event];
        });

        $this->publisher->publish($done[1]);

        return $done[0];
    }

    /**
     * @return array<string, int>
     */
    private function defaultParams(string $type, Money $reference): array
    {
        $low = (int) intdiv($reference->cents * 85, 100);

        return match ($type) {
            'penny_pincher' => ['floor' => $low, 'every' => 3],
            'anchor' => ['price' => $reference->cents + 50],
            'matcher' => ['floor' => $low, 'every' => 3],
            'sleeper' => ['price' => $reference->cents - 50, 'awake_ticks' => 60, 'asleep_ticks' => 40],
            'chaos' => ['min' => $low, 'max' => $reference->cents + 300, 'every' => 3, 'react_bps' => 5000],
            default => [],
        };
    }
}
