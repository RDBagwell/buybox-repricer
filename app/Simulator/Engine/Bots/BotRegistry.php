<?php

namespace App\Simulator\Engine\Bots;

use InvalidArgumentException;

/**
 * Maps bot keys to strategy classes. Add a bot by implementing CompetitorBot and listing it here.
 */
final class BotRegistry
{
    /** @var array<string, CompetitorBot> */
    private array $bots = [];

    /**
     * @param  list<class-string<CompetitorBot>>  $classes
     */
    public function __construct(array $classes = [PennyPincher::class, Anchor::class])
    {
        foreach ($classes as $class) {
            $this->bots[$class::key()] = new $class;
        }
    }

    public function get(string $key): CompetitorBot
    {
        return $this->bots[$key] ?? throw new InvalidArgumentException("Unknown bot [{$key}].");
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->bots);
    }
}
