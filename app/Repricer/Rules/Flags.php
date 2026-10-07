<?php

namespace App\Repricer\Rules;

final readonly class Flags
{
    public function __construct(
        public bool $killSwitch = false,
        public bool $dryRun = false,
        public bool $productPaused = false,
    ) {}
}
