<?php

namespace App\Simulator\Engine;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * The only source of randomness inside the simulation. Seeded, and its full internal state
 * can be exported/restored so a persisted simulation continues exactly where it stopped.
 */
final class SeededRng
{
    private Xoshiro256StarStar $engine;

    private Randomizer $randomizer;

    private function __construct(Xoshiro256StarStar $engine)
    {
        $this->engine = $engine;
        $this->randomizer = new Randomizer($engine);
    }

    public static function fromSeed(int $seed): self
    {
        return new self(new Xoshiro256StarStar($seed));
    }

    public static function restore(string $state): self
    {
        $engine = unserialize(base64_decode($state), ['allowed_classes' => [Xoshiro256StarStar::class]]);
        if (! $engine instanceof Xoshiro256StarStar) {
            throw new \RuntimeException('Corrupt RNG state.');
        }

        return new self($engine);
    }

    public function export(): string
    {
        return base64_encode(serialize($this->engine));
    }

    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /** True with probability $bps / 10000. */
    public function chance(int $bps): bool
    {
        return $bps > 0 && $this->int(1, 10_000) <= $bps;
    }
}
