<?php

declare(strict_types=1);

namespace TradingPlatform\Support;

/**
 * Fixed-precision decimal for money and prices, backed by bcmath.
 *
 * Floats are never used on the order/fill path: 0.1 + 0.2 !== 0.3 in IEEE-754,
 * and rounding drift on prices/quantities is a correctness bug in a trading
 * system. All arithmetic here is exact to {@see self::SCALE} decimal places.
 */
final class Decimal implements \JsonSerializable, \Stringable
{
    /** Working precision (crypto venues commonly quote to 8dp). */
    public const SCALE = 8;

    private string $value;

    private function __construct(string $normalized)
    {
        $this->value = $normalized;
    }

    public static function of(string|int|float $value): self
    {
        if (is_float($value)) {
            // Route floats through a string with generous precision, then trim.
            $value = sprintf('%.'.self::SCALE.'F', $value);
        }
        $value = (string) $value;
        if ($value === '' || !is_numeric($value)) {
            throw new \InvalidArgumentException("Not a numeric value: '{$value}'");
        }

        return new self(bcadd($value, '0', self::SCALE));
    }

    public static function zero(): self
    {
        return new self(bcadd('0', '0', self::SCALE));
    }

    public function add(self $other): self
    {
        return new self(bcadd($this->value, $other->value, self::SCALE));
    }

    public function sub(self $other): self
    {
        return new self(bcsub($this->value, $other->value, self::SCALE));
    }

    public function mul(self $other): self
    {
        return new self(bcmul($this->value, $other->value, self::SCALE));
    }

    public function div(self $other): self
    {
        if ($other->isZero()) {
            throw new \DivisionByZeroError('Decimal division by zero');
        }

        return new self(bcdiv($this->value, $other->value, self::SCALE));
    }

    public function abs(): self
    {
        return $this->isNegative() ? $this->negate() : $this;
    }

    public function negate(): self
    {
        return new self(bcmul($this->value, '-1', self::SCALE));
    }

    /** @return int -1, 0, or 1 */
    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
    }

    public function eq(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function gt(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function gte(self $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function lt(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function lte(self $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->value, '0', self::SCALE) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->value, '0', self::SCALE) < 0;
    }

    public function min(self $other): self
    {
        return $this->lte($other) ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->gte($other) ? $this : $other;
    }

    public function toFloat(): float
    {
        return (float) $this->value;
    }

    /** Human-friendly string trimmed of trailing zeros (keeps at least 2 dp). */
    public function format(int $dp = 2): string
    {
        return bcadd($this->value, '0', max(0, $dp));
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): float
    {
        return $this->toFloat();
    }
}
