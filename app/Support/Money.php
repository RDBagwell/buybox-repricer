<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of money in integer cents (single currency). Immutable.
 *
 * Floats never enter: percentages are expressed in basis points (1% = 100 bps)
 * and every division names its rounding explicitly.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(public int $cents) {}

    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse a decimal string such as "12.34" or "12" without going through a float.
     */
    public static function parse(string $amount): self
    {
        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $m) !== 1) {
            throw new InvalidArgumentException("Not a money amount: [{$amount}]");
        }

        $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return new self($m[1] === '-' ? -$cents : $cents);
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    /**
     * $basisPoints / 10000 of this amount, rounded as specified.
     */
    public function percentage(int $basisPoints, Rounding $rounding): self
    {
        return new self(self::divide($this->cents * $basisPoints, 10_000, $rounding));
    }

    public function abs(): self
    {
        return new self(abs($this->cents));
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function compare(self $other): int
    {
        return $this->cents <=> $other->cents;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->cents >= $other->cents;
    }

    public function lessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->cents <= $other->cents;
    }

    public static function max(self $first, self ...$rest): self
    {
        foreach ($rest as $m) {
            if ($m->cents > $first->cents) {
                $first = $m;
            }
        }

        return $first;
    }

    public static function min(self $first, self ...$rest): self
    {
        foreach ($rest as $m) {
            if ($m->cents < $first->cents) {
                $first = $m;
            }
        }

        return $first;
    }

    public function clamp(self $min, self $max): self
    {
        if ($min->greaterThan($max)) {
            throw new InvalidArgumentException("Cannot clamp: min {$min} is above max {$max}");
        }

        return self::min(self::max($this, $min), $max);
    }

    /** "12.34" (no currency symbol); for logs, traces and CLI output. */
    public function format(): string
    {
        $abs = abs($this->cents);

        return ($this->cents < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /** JSON carries integer cents, never a float. */
    public function jsonSerialize(): int
    {
        return $this->cents;
    }

    /**
     * Integer division with explicit rounding. PHP's intdiv truncates toward zero,
     * which is none of our modes for negative numbers, so each mode is spelled out.
     */
    public static function divide(int $numerator, int $denominator, Rounding $rounding): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator; // same sign as numerator

        if ($remainder === 0) {
            return $quotient;
        }

        return match ($rounding) {
            Rounding::Down => $numerator < 0 ? $quotient - 1 : $quotient,
            Rounding::Up => $numerator > 0 ? $quotient + 1 : $quotient,
            Rounding::HalfUp => abs($remainder) * 2 >= $denominator
                ? $quotient + ($numerator < 0 ? -1 : 1)
                : $quotient,
        };
    }
}
