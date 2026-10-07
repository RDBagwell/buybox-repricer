<?php

namespace App\Simulator\Persistence;

use App\Simulator\Engine\AnyOfferChanged;
use App\Simulator\Engine\BuyBoxScorer;
use App\Simulator\Engine\Listing;
use App\Simulator\Engine\Scenario;
use App\Simulator\Engine\SeededRng;
use App\Simulator\Engine\SimClock;
use App\Simulator\Engine\SimOffer;
use App\Simulator\Engine\World;
use App\Support\Fulfillment;
use App\Support\Money;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Loads and saves the World to the sim_ tables. Never touches any other table.
 *
 * Writers (the tick runner and the adapter's price updates) take a row lock on sim_state
 * inside their transaction, which serialises them against each other.
 */
final class WorldRepository
{
    public const STATE = 'sim_state';

    public const LISTINGS = 'sim_listings';

    public const OFFERS = 'sim_offers';

    public const EVENTS = 'sim_events';

    public const PRICE_REQUESTS = 'sim_price_requests';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function db(): ConnectionInterface
    {
        return $this->db;
    }

    public function exists(): bool
    {
        return $this->db->table(self::STATE)->where('id', 1)->exists();
    }

    /**
     * Rebuild the world from the scenario with a new seed. Market time never goes backwards:
     * a reset keeps the current clock (or starts at the epoch on a fresh database).
     *
     * @param  list<array{asin: string, title: string, offers: list<array<string, mixed>>}>  $scenario
     */
    public function reset(array $scenario, int $seed, string $ourSellerId, string $epoch, int $tickSeconds, BuyBoxScorer $scorer): World
    {
        return $this->db->transaction(function () use ($scenario, $seed, $ourSellerId, $epoch, $tickSeconds, $scorer) {
            $state = $this->db->table(self::STATE)->where('id', 1)->lockForUpdate()->first();
            $clock = SimClock::at($epoch, $tickSeconds);
            if ($state !== null) {
                $clock->nowMs = max($clock->nowMs, (int) $state->clock_ms);
            }

            $world = Scenario::build($scenario, $seed, $ourSellerId, $clock, $scorer);

            $this->db->table(self::OFFERS)->delete();
            $this->db->table(self::LISTINGS)->delete();

            $now = now();
            foreach ($world->listings as $listing) {
                $this->db->table(self::LISTINGS)->insert([
                    'asin' => $listing->asin, 'title' => $listing->title,
                    'buybox_seller_id' => $listing->buyBoxSellerId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($listing->offers as $offer) {
                    $this->db->table(self::OFFERS)->insert($this->offerRow($listing->asin, $offer) + ['created_at' => $now, 'updated_at' => $now]);
                }
            }

            $this->db->table(self::STATE)->updateOrInsert(['id' => 1], [
                'seed' => $seed,
                'rng_state' => $world->rng->export(),
                'tick' => 0,
                'clock_ms' => $world->clock->nowMs,
                'run' => $state === null ? 1 : ((int) $state->run) + 1,
                'created_at' => $state->created_at ?? $now,
                'updated_at' => $now,
            ]);

            return $world;
        });
    }

    /**
     * Load the world. Call inside a transaction with $lock = true before mutating it.
     */
    public function load(bool $lock = false): World
    {
        $query = $this->db->table(self::STATE)->where('id', 1);
        $state = ($lock ? $query->lockForUpdate() : $query)->first()
            ?? throw new RuntimeException('Simulator not initialised. Run `php artisan sim:reset` or seed the database.');

        $listings = [];
        $titles = $this->db->table(self::LISTINGS)->orderBy('asin')->get();
        foreach ($titles as $row) {
            $listings[(string) $row->asin] = new Listing((string) $row->asin, (string) $row->title, [], $row->buybox_seller_id === null ? null : (string) $row->buybox_seller_id);
        }

        foreach ($this->db->table(self::OFFERS)->orderBy('asin')->orderBy('seller_id')->get() as $row) {
            $listings[(string) $row->asin]->put($this->hydrateOffer($row));
        }

        $tickSeconds = (int) config('simulator.tick_seconds', 15);

        return new World($listings, new SimClock((int) $state->clock_ms, $tickSeconds * 1000), SeededRng::restore((string) $state->rng_state), (int) $state->tick, (int) $state->seed);
    }

    /**
     * Persist the outcome of a tick: clock, RNG, Buy Box winners and every bot-driven offer.
     * Offers without a bot (ours) are only ever written by saveOffer().
     */
    public function saveTick(World $world): void
    {
        $now = now();
        $this->db->table(self::STATE)->where('id', 1)->update([
            'tick' => $world->tick,
            'clock_ms' => $world->clock->nowMs,
            'rng_state' => $world->rng->export(),
            'updated_at' => $now,
        ]);

        foreach ($world->listings as $listing) {
            $this->db->table(self::LISTINGS)->where('asin', $listing->asin)->update(['buybox_seller_id' => $listing->buyBoxSellerId, 'updated_at' => $now]);
            foreach ($listing->offers as $offer) {
                if ($offer->bot !== null) {
                    $this->db->table(self::OFFERS)->where('asin', $listing->asin)->where('seller_id', $offer->sellerId)->update([
                        'price' => $offer->price->cents,
                        'bot_memory' => json_encode((object) $offer->botMemory, JSON_THROW_ON_ERROR),
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function saveOffer(Listing $listing, SimOffer $offer): void
    {
        $now = now();
        $this->db->table(self::OFFERS)->where('asin', $listing->asin)->where('seller_id', $offer->sellerId)->update(['price' => $offer->price->cents, 'updated_at' => $now]);
        $this->db->table(self::LISTINGS)->where('asin', $listing->asin)->update(['buybox_seller_id' => $listing->buyBoxSellerId, 'updated_at' => $now]);
    }

    public function findAsinForSku(string $sellerId, string $sku): ?string
    {
        $asin = $this->db->table(self::OFFERS)->where('seller_id', $sellerId)->where('sku', $sku)->value('asin');

        return $asin === null ? null : (string) $asin;
    }

    public function recordEvent(AnyOfferChanged $event, int $run): void
    {
        $this->db->table(self::EVENTS)->insert([
            'event_id' => $event->eventId,
            'run' => $run,
            'asin' => $event->asin,
            'tick' => $event->tick,
            'market_time_ms' => $event->marketTimeMs,
            'payload' => json_encode($event->toPayload(), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    public function currentRun(): int
    {
        return (int) $this->db->table(self::STATE)->where('id', 1)->value('run');
    }

    public function clockMs(): int
    {
        $ms = $this->db->table(self::STATE)->where('id', 1)->value('clock_ms');

        return $ms === null ? throw new RuntimeException('Simulator not initialised.') : (int) $ms;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPriceRequest(string $idempotencyKey): ?array
    {
        $response = $this->db->table(self::PRICE_REQUESTS)->where('idempotency_key', $idempotencyKey)->value('response');

        if ($response === null) {
            return null;
        }

        /** @var array<string, mixed> */
        return json_decode((string) $response, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function recordPriceRequest(string $idempotencyKey, string $sellerId, string $sku, Money $price, int $appliedAtMs, array $response): void
    {
        $this->db->table(self::PRICE_REQUESTS)->insert([
            'idempotency_key' => $idempotencyKey,
            'seller_id' => $sellerId,
            'sku' => $sku,
            'price' => $price->cents,
            'applied_at_ms' => $appliedAtMs,
            'response' => json_encode($response, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function offerRow(string $asin, SimOffer $offer): array
    {
        return [
            'asin' => $asin,
            'seller_id' => $offer->sellerId,
            'sku' => $offer->sku,
            'price' => $offer->price->cents,
            'shipping' => $offer->shipping->cents,
            'fulfillment' => $offer->fulfillment->value,
            'rating' => $offer->rating,
            'handling_days' => $offer->handlingDays,
            'bot' => $offer->bot,
            'bot_params' => json_encode((object) $offer->botParams, JSON_THROW_ON_ERROR),
            'bot_memory' => json_encode((object) $offer->botMemory, JSON_THROW_ON_ERROR),
        ];
    }

    private function hydrateOffer(\stdClass $row): SimOffer
    {
        /** @var array<string, int|string> $params */
        $params = json_decode((string) $row->bot_params, true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, int|string> $memory */
        $memory = json_decode((string) $row->bot_memory, true, flags: JSON_THROW_ON_ERROR);

        return new SimOffer(
            (string) $row->seller_id,
            Money::cents((int) $row->price),
            Money::cents((int) $row->shipping),
            Fulfillment::from((string) $row->fulfillment),
            (int) $row->rating,
            (int) $row->handling_days,
            $row->sku === null ? null : (string) $row->sku,
            $row->bot === null ? null : (string) $row->bot,
            $params,
            $memory,
        );
    }
}
