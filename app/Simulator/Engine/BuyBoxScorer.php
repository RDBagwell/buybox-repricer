<?php

namespace App\Simulator\Engine;

use App\Support\Fulfillment;
use App\Support\Money;
use App\Support\Rounding;

/**
 * Deterministic Buy Box approximation. The real marketplace algorithm is private; this one is
 * documented and simple on purpose:
 *
 *   - offers rated below min_rating are disqualified;
 *   - effective price = landed - marketplace-fulfilment bonus + handling-time penalty
 *     (both percentages of landed, rounded half-up to the cent), so landed price dominates;
 *   - lowest effective price wins;
 *   - ties go to the incumbent (avoids flapping); otherwise lowest landed, then seller id.
 */
final readonly class BuyBoxScorer
{
    public function __construct(
        public int $minRating = 80,
        public int $marketplaceBonusBps = 300,
        public int $freeHandlingDays = 2,
        public int $handlingPenaltyBpsPerDay = 100,
    ) {}

    /**
     * @param  array{min_rating?: int, marketplace_bonus_bps?: int, free_handling_days?: int, handling_penalty_bps_per_day?: int}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            $config['min_rating'] ?? 80,
            $config['marketplace_bonus_bps'] ?? 300,
            $config['free_handling_days'] ?? 2,
            $config['handling_penalty_bps_per_day'] ?? 100,
        );
    }

    public function score(SimOffer $offer): BuyBoxScore
    {
        $landed = $offer->landed();

        if ($offer->rating < $this->minRating) {
            return new BuyBoxScore($offer->sellerId, $landed, null, "disqualified: rating {$offer->rating} < {$this->minRating}");
        }

        $effective = $landed;
        $notes = [];

        if ($offer->fulfillment === Fulfillment::Marketplace) {
            $bonus = $landed->percentage($this->marketplaceBonusBps, Rounding::HalfUp);
            $effective = $effective->minus($bonus);
            $notes[] = "marketplace bonus -{$bonus}";
        }

        $extraDays = max(0, $offer->handlingDays - $this->freeHandlingDays);
        if ($extraDays > 0) {
            $penalty = $landed->percentage($this->handlingPenaltyBpsPerDay * $extraDays, Rounding::HalfUp);
            $effective = $effective->plus($penalty);
            $notes[] = "handling +{$penalty}";
        }

        return new BuyBoxScore($offer->sellerId, $landed, $effective, $notes === [] ? 'landed' : implode(', ', $notes));
    }

    /**
     * @param  array<string, SimOffer>|list<SimOffer>  $offers
     */
    public function decide(array $offers, ?string $incumbent): BuyBoxResult
    {
        $scores = array_map(fn (SimOffer $o) => $this->score($o), array_values($offers));
        $eligible = array_values(array_filter($scores, fn (BuyBoxScore $s) => $s->effective !== null));

        if ($eligible === []) {
            return new BuyBoxResult(null, $scores, 'No eligible offers; Buy Box suppressed.');
        }

        $best = Money::min(...array_map(fn (BuyBoxScore $s) => $s->effective ?? $s->landed, $eligible));
        $tied = array_values(array_filter($eligible, fn (BuyBoxScore $s) => $s->effective !== null && $s->effective->equals($best)));

        foreach ($tied as $s) {
            if ($s->sellerId === $incumbent) {
                $why = count($tied) > 1 ? 'tie at effective '.$best.' kept by incumbent' : 'lowest effective '.$best;

                return new BuyBoxResult($incumbent, $scores, $why);
            }
        }

        usort($tied, fn (BuyBoxScore $a, BuyBoxScore $b) => [$a->landed->cents, $a->sellerId] <=> [$b->landed->cents, $b->sellerId]);

        return new BuyBoxResult($tied[0]->sellerId, $scores, 'lowest effective '.$best);
    }
}
