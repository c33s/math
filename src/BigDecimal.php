<?php

namespace Brick\Math;

use Brick\Math\Internal\Calculator;

/**
 * Immutable, arbitrary-precision signed decimal numbers.
 */
class BigDecimal implements \Serializable
{
    /**
     * The unscaled value of this decimal number.
     *
     * This is a string of digits with an optional leading minus sign.
     * No leading zero must be present.
     * No leading minus sign must be present if the value is 0.
     *
     * @var string
     */
    private $value;

    /**
     * The scale (number of digits after the decimal point) of this decimal number.
     *
     * This must be zero or more.
     *
     * @var integer
     */
    private $scale;

    /**
     * Private constructor. Use the factory methods.
     *
     * @param string  $value The unscaled value, validated.
     * @param integer $scale The scale, validated.
     */
    private function __construct($value, $scale = 0)
    {
        $this->value = $value;
        $this->scale = $scale;
    }

    /**
     * Returns a decimal of the given value.
     *
     * Note: you should avoid passing floating point numbers to this method.
     * Being imprecise by design, they might not convert to the decimal value you expect.
     * This would defeat the whole purpose of using the Decimal type.
     * Prefer passing decimal numbers as strings, e.g `Decimal::of('0.1')` over `Decimal::of(0.1)`.
     *
     * @param BigDecimal|number|string $value
     *
     * @return BigDecimal
     *
     * @throws \InvalidArgumentException If the number is malformed.
     */
    public static function of($value)
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        if (is_int($value)) {
            return new BigDecimal((string) $value);
        }

        $value = (string) $value;

        if (preg_match('/^([\-\+])?([0-9]+)(?:\.([0-9]+))?(?:[eE]([\-\+]?[0-9]+))?()$/', $value, $matches) === 0) {
            throw new \InvalidArgumentException(sprintf('%s does not represent a valid decimal number.', $value));
        }

        list (, $sign, $integer, $fraction, $exponent) = $matches;

        if ($sign === '+') {
            $sign = '';
        }

        $value = ltrim($integer . $fraction, '0');
        $value = ($value === '') ? '0' : $sign . $value;

        $scale = strlen($fraction) - $exponent;

        if ($scale < 0) {
            if ($value !== '0') {
                $value .= str_repeat('0', - $scale);
            }
            $scale = 0;
        }

        return new BigDecimal($value, $scale);
    }

    /**
     * @param BigInteger|integer|string $value An integer representing the unscaled value of the number.
     * @param integer                   $scale The scale of the number.
     *
     * @return BigDecimal
     */
    public static function ofUnscaledValue($value, $scale = 0)
    {
        $scale = (int) $scale;

        if ($scale < 0) {
            throw new \InvalidArgumentException('The scale cannot be negative.');
        }

        if (is_int($value)) {
            return new BigDecimal((string) $value, $scale);
        }

        if (! $value instanceof BigInteger) {
            $value = BigInteger::of($value);
        }

        return new BigDecimal((string) $value, $scale);
    }

    /**
     * Returns a decimal number representing zero.
     *
     * This value is cached to optimize memory consumption as it is frequently used.
     *
     * @return BigDecimal
     */
    public static function zero()
    {
        static $zero = null;

        if ($zero === null) {
            $zero = new BigDecimal('0');
        }

        return $zero;
    }

    /**
     * Returns the minimum of the given values.
     *
     * @param BigDecimal|number|string ...$values The numbers to compare.
     *
     * @return BigDecimal The minimum value.
     *
     * @throws \InvalidArgumentException If no values are given, or an invalid value is given.
     */
    public static function min(...$values)
    {
        $min = null;

        foreach ($values as $value) {
            $value = BigDecimal::of($value);
            if ($min === null || $value->isLessThan($min)) {
                $min = $value;
            }
        }

        if ($min === null) {
            throw new \InvalidArgumentException(__METHOD__ . '() expects at least one value.');
        }

        return $min;
    }

    /**
     * Returns the maximum of the given values.
     *
     * @param BigDecimal|number|string ...$values The numbers to compare.
     *
     * @return BigDecimal The maximum value.
     *
     * @throws \InvalidArgumentException If no values are given, or an invalid value is given.
     */
    public static function max(...$values)
    {
        $max = null;

        foreach ($values as $value) {
            $value = BigDecimal::of($value);
            if ($max === null || $value->isGreaterThan($max)) {
                $max = $value;
            }
        }

        if ($max === null) {
            throw new \InvalidArgumentException(__METHOD__ . '() expects at least one value.');
        }

        return $max;
    }

    /**
     * Returns the sum of this number and the given one.
     *
     * The result has a scale of `max($this->scale, $that->scale)`.
     *
     * @param BigDecimal|number|string $that
     *
     * @return BigDecimal
     */
    public function plus($that)
    {
        $that = BigDecimal::of($that);

        if ($that->value === '0' && $that->scale <= $this->scale) {
            return $this;
        }

        $this->scaleValues($this, $that, $a, $b);

        $value = Calculator::get()->add($a, $b);
        $scale = $this->scale > $that->scale ? $this->scale : $that->scale;

        return new BigDecimal($value, $scale);
    }

    /**
     * Returns the difference of this number and the given one.
     *
     * The result has a scale of `max($this->scale, $that->scale)`.
     *
     * @param BigDecimal|number|string $that
     *
     * @return BigDecimal
     */
    public function minus($that)
    {
        $that = BigDecimal::of($that);

        if ($that->value === '0' && $that->scale <= $this->scale) {
            return $this;
        }

        $this->scaleValues($this, $that, $a, $b);

        $value = Calculator::get()->sub($a, $b);
        $scale = $this->scale > $that->scale ? $this->scale : $that->scale;

        return new BigDecimal($value, $scale);
    }

    /**
     * Returns the result of the multiplication of this number and the given one.
     *
     * The result has a scale of `$this->scale + $that->scale`.
     *
     * @param BigDecimal|number|string $that
     *
     * @return BigDecimal
     */
    public function multipliedBy($that)
    {
        $that = BigDecimal::of($that);

        if ($that->value === '1' && $that->scale === 0) {
            return $this;
        }

        $value = Calculator::get()->mul($this->value, $that->value);
        $scale = $this->scale + $that->scale;

        return new BigDecimal($value, $scale);
    }

    /**
     * Returns the result of the division of this number and the given one.
     *
     * @param BigDecimal|number|string $that         The divisor.
     * @param int                      $roundingMode The rounding mode.
     * @param int|null                 $scale        The desired scale, or null to use the scale of this number.
     *
     * @return BigDecimal
     *
     * @throws ArithmeticException       If RoundingMode::UNNECESSARY is provided and rounding was necessary.
     * @throws \InvalidArgumentException If any of the arguments is not valid.
     */
    public function dividedBy($that, $roundingMode = RoundingMode::UNNECESSARY, $scale = null)
    {
        $that = BigDecimal::of($that);

        if ($that->isZero()) {
            throw ArithmeticException::divisionByZero();
        }

        if ($scale === null) {
            $scale = $this->scale;
        } else {
            $scale = (int) $scale;

            if ($scale < 0) {
                throw new \InvalidArgumentException('Scale cannot be negative.');
            }
        }

        if ($that->value === '1' && $scale === $this->scale) {
            return $this;
        }

        $p = $this->valueWithMinScale($that->scale + $scale);
        $q = $that->valueWithMinScale($this->scale - $scale);

        $calculator = Calculator::get();

        list ($result, $remainder) = $calculator->div($p, $q);

        $hasDiscardedFraction = ($remainder !== '0');
        $isPositiveOrZero = ($p[0] === '-') === ($q[0] === '-');

        $discardedFractionSign = function() use ($calculator, $remainder, $q) {
            $r = $calculator->abs($calculator->mul($remainder, '2'));
            $q = $calculator->abs($q);

            return $calculator->cmp($r, $q);
        };

        $increment = false;

        switch ($roundingMode) {
            case RoundingMode::UNNECESSARY:
                if ($hasDiscardedFraction) {
                    throw ArithmeticException::roundingNecessary();
                }
                break;

            case RoundingMode::UP:
                $increment = $hasDiscardedFraction;
                break;

            case RoundingMode::DOWN:
                break;

            case RoundingMode::CEILING:
                $increment = $hasDiscardedFraction && $isPositiveOrZero;
                break;

            case RoundingMode::FLOOR:
                $increment = $hasDiscardedFraction && ! $isPositiveOrZero;
                break;

            case RoundingMode::HALF_UP:
                $increment = $discardedFractionSign() >= 0;
                break;

            case RoundingMode::HALF_DOWN:
                $increment = $discardedFractionSign() > 0;
                break;

            case RoundingMode::HALF_CEILING:
                $increment = $isPositiveOrZero ? $discardedFractionSign() >= 0 : $discardedFractionSign() > 0;
                break;

            case RoundingMode::HALF_FLOOR:
                $increment = $isPositiveOrZero ? $discardedFractionSign() > 0 : $discardedFractionSign() >= 0;
                break;

            case RoundingMode::HALF_EVEN:
                $lastDigit = (int) substr($result, -1);
                $lastDigitIsEven = ($lastDigit % 2 === 0);
                $increment = $lastDigitIsEven ? $discardedFractionSign() > 0 : $discardedFractionSign() >= 0;
                break;

            default:
                throw new \InvalidArgumentException('Invalid rounding mode.');
        }

        if ($increment) {
            $result = $calculator->add($result, $isPositiveOrZero ? '1' : '-1');
        }

        return new BigDecimal($result, $scale);
    }

    /**
     * Returns the result of the division of this number and the given one.
     *
     * The result has a minimum scale of `$this->scale`, expandable to accommodate the exact result of the division.
     *
     * If the result cannot be represented as a finite decimal number, an exception is thrown.
     *
     * @param BigDecimal|number|string $that The divisor.
     *
     * @return BigDecimal
     *
     * @throws ArithmeticException If the result cannot be represented as a finite decimal number.
     */
    public function dividedByExact($that)
    {
        $that = BigDecimal::of($that);

        $result = $this->toBigRational()->dividedBy($that->toBigRational())->toBigDecimal();

        if ($result->scale < $this->scale) {
            $result = $result->withScale($this->scale);
        }

        return $result;
    }

    /**
     * Returns the quotient and remainder of the division of this number and the given one.
     *
     * The quotient has a scale of `0`, and the remainder has a scale of `max($this->scale, $that->scale)`.
     *
     * @param BigDecimal|number|string $that The number to divide.
     *
     * @return BigDecimal[] An array containing the quotient and the remainder.
     *
     * @throws ArithmeticException If the divisor is zero.
     */
    public function divideAndRemainder($that)
    {
        $that = BigDecimal::of($that);

        if ($that->isZero()) {
            throw ArithmeticException::divisionByZero();
        }

        $p = $this->valueWithMinScale($that->scale);
        $q = $that->valueWithMinScale($this->scale);

        list ($quotient, $remainder) = Calculator::get()->div($p, $q);

        $scale = $this->scale > $that->scale ? $this->scale : $that->scale;

        $quotient = new BigDecimal($quotient, 0);
        $remainder = new BigDecimal($remainder, $scale);

        return [$quotient, $remainder];
    }

    /**
     * Returns this number exponentiated.
     *
     * The exponent has a limit of 1 million.
     *
     * @param integer $exponent The exponent, between 0 and 1,000,000.
     *
     * @return BigDecimal
     *
     * @throws \InvalidArgumentException If the exponent is not in the allowed range.
     */
    public function power($exponent)
    {
        $exponent = (int) $exponent;

        if ($exponent === 1) {
            return $this;
        }

        if ($exponent < 0 || $exponent > Calculator::MAX_POWER) {
            throw new \InvalidArgumentException(sprintf(
                'The exponent %d is not in the range 0 to %d.',
                $exponent,
                Calculator::MAX_POWER
            ));
        }

        return new BigDecimal(Calculator::get()->pow($this->value, $exponent), $this->scale * $exponent);
    }

    /**
     * Returns a Decimal with the current value and the specified scale.
     *
     * @param integer $scale
     * @param integer $roundingMode
     *
     * @return BigDecimal
     */
    public function withScale($scale, $roundingMode = RoundingMode::UNNECESSARY)
    {
        if ($scale == $this->scale) {
            return $this;
        }

        return $this->dividedBy(1, $roundingMode, $scale);
    }

    /**
     * Returns a copy of this BigDecimal with the decimal point moved $n places to the left.
     *
     * @param integer $n
     *
     * @return BigDecimal
     */
    public function withPointMovedLeft($n)
    {
        $n = (int) $n;

        if ($n === 0) {
            return $this;
        }

        if ($n < 0) {
            return $this->withPointMovedRight(-$n);
        }

        return new BigDecimal($this->value, $this->scale + $n);
    }

    /**
     * Returns a copy of this BigDecimal with the decimal point moved $n places to the right.
     *
     * @param integer $n
     *
     * @return BigDecimal
     */
    public function withPointMovedRight($n)
    {
        $n = (int) $n;

        if ($n === 0) {
            return $this;
        }

        if ($n < 0) {
            return $this->withPointMovedLeft(-$n);
        }

        $value = $this->value;
        $scale = $this->scale - $n;

        if ($scale < 0) {
            $calculator = Calculator::get();
            $power = $calculator->pow('10', -$scale);
            $value = $calculator->mul($value, $power);
            $scale = 0;
        }

        return new BigDecimal($value, $scale);
    }

    /**
     * Returns a copy of this BigDecimal with any trailing zeros removed from the fractional part.
     *
     * @return BigDecimal
     */
    public function stripTrailingZeros()
    {
        if ($this->scale === 0) {
            return $this;
        }

        $trimmedValue = rtrim($this->value, '0');

        if ($trimmedValue === '') {
            return new BigDecimal('0');
        }

        $trimmableZeros = strlen($this->value) - strlen($trimmedValue);

        if ($trimmableZeros === 0) {
            return $this;
        }

        if ($trimmableZeros > $this->scale) {
            $trimmableZeros = $this->scale;
        }

        $value = substr($this->value, 0, -$trimmableZeros);
        $scale = $this->scale - $trimmableZeros;

        return new BigDecimal($value, $scale);
    }

    /**
     * Returns the absolute value of this number.
     *
     * @return BigDecimal
     */
    public function abs()
    {
        return $this->isNegative() ? $this->negated() : $this;
    }

    /**
     * Returns the negated value of this number.
     *
     * @return BigDecimal
     */
    public function negated()
    {
        return new BigDecimal(Calculator::get()->neg($this->value), $this->scale);
    }

    /**
     * Compares this number to the given one.
     *
     * @param BigDecimal|number|string $that
     *
     * @return integer [-1,0,1]
     */
    public function compareTo($that)
    {
        $that = BigDecimal::of($that);
        $this->scaleValues($this, $that, $a, $b);

        return Calculator::get()->cmp($a, $b);
    }

    /**
     * Checks if this number is equal to the given one.
     *
     * @param BigDecimal|number|string $that
     *
     * @return boolean
     */
    public function isEqualTo($that)
    {
        return $this->compareTo($that) === 0;
    }

    /**
     * Checks if this number is strictly lower than the given one.
     *
     * @param BigDecimal|number|string $that
     *
     * @return boolean
     */
    public function isLessThan($that)
    {
        return $this->compareTo($that) < 0;
    }

    /**
     * Checks if this number is lower than or equal to the given one.
     *
     * @param BigDecimal|number|string $that
     *
     * @return boolean
     */
    public function isLessThanOrEqualTo($that)
    {
        return $this->compareTo($that) <= 0;
    }

    /**
     * Checks if this number is strictly greater than the given one.
     *
     * @param BigDecimal|number|string $that
     *
     * @return boolean
     */
    public function isGreaterThan($that)
    {
        return $this->compareTo($that) > 0;
    }

    /**
     * Checks if this number is greater than or equal to the given one.
     *
     * @param BigDecimal|number|string $that
     *
     * @return boolean
     */
    public function isGreaterThanOrEqualTo($that)
    {
        return $this->compareTo($that) >= 0;
    }

    /**
     * Returns the sign of this number.
     *
     * @return int -1 if the number is negative, 0 if zero, 1 if positive.
     */
    public function getSign()
    {
        return ($this->value === '0') ? 0 : (($this->value[0] === '-') ? -1 : 1);
    }

    /**
     * Checks if this number equals zero.
     *
     * @return boolean
     */
    public function isZero()
    {
        return ($this->value === '0');
    }

    /**
     * Checks if this number is strictly negative.
     *
     * @return boolean
     */
    public function isNegative()
    {
        return ($this->value[0] === '-');
    }

    /**
     * Checks if this number is negative or zero.
     *
     * @return boolean
     */
    public function isNegativeOrZero()
    {
        return ($this->value === '0') || ($this->value[0] === '-');
    }

    /**
     * Checks if this number is strictly positive.
     *
     * @return boolean
     */
    public function isPositive()
    {
        return ($this->value !== '0') && ($this->value[0] !== '-');
    }

    /**
     * Checks if this number is positive or zero.
     *
     * @return boolean
     */
    public function isPositiveOrZero()
    {
        return ($this->value[0] !== '-');
    }

    /**
     * @return string
     */
    public function getUnscaledValue()
    {
        return $this->value;
    }

    /**
     * @return integer
     */
    public function getScale()
    {
        return $this->scale;
    }

    /**
     * Returns a string representing the integral part of this decimal number.
     *
     * Example: `-123.456` => `-123`.
     *
     * @return string
     */
    public function getIntegral()
    {
        if ($this->scale === 0) {
            return $this->value;
        }

        $value = $this->getUnscaledValueWithLeadingZeros();

        return substr($value, 0, -$this->scale);
    }

    /**
     * Returns a string representing the fractional part of this decimal number.
     *
     * If the scale is zero, an empty string is returned.
     *
     * Examples: `-123.456` => '456', `123` => ''.
     *
     * @return string
     */
    public function getFraction()
    {
        if ($this->scale === 0) {
            return '';
        }

        $value = $this->getUnscaledValueWithLeadingZeros();

        return substr($value, -$this->scale);
    }

    /**
     * Converts this BigDecimal to a BigInteger, using rounding if necessary.
     *
     * @param integer $roundingMode
     *
     * @return BigInteger
     */
    public function toBigInteger($roundingMode = RoundingMode::UNNECESSARY)
    {
        if ($this->scale === 0) {
            return BigInteger::of($this->value);
        }

        return BigInteger::of($this->dividedBy(1, $roundingMode, 0)->value);
    }

    /**
     * Converts this decimal number to a rational number, in its simplest form.
     *
     * @return BigRational
     */
    public function toBigRational()
    {
        $numerator = $this->value;
        $denominator = '1' . str_repeat('0', $this->scale);

        return BigRational::of($numerator, $denominator)->simplified();
    }

    /**
     * Returns a string representation of this number.
     *
     * @return string
     */
    public function __toString()
    {
        if ($this->scale === 0) {
            return $this->value;
        }

        $value = $this->getUnscaledValueWithLeadingZeros();

        return substr($value, 0, -$this->scale) . '.' . substr($value, -$this->scale);
    }

    /**
     * This method is required by interface Serializable and SHOULD NOT be accessed directly.
     *
     * @internal
     *
     * @return string
     */
    public function serialize()
    {
        return $this->value . ':' . $this->scale;
    }

    /**
     * This method is required by interface Serializable and MUST NOT be accessed directly.
     *
     * @internal
     *
     * @param string $value
     *
     * @return void
     */
    public function unserialize($value)
    {
        if ($this->value !== null || $this->scale !== null) {
            throw new \LogicException('unserialize() is an internal function, it must not be called directly.');
        }

        list ($value, $scale) = explode(':', $value);

        $this->value = $value;
        $this->scale = (int) $scale;
    }

    /**
     * Puts the internal values of the given decimal numbers on the same scale.
     *
     * @param BigDecimal $x The first decimal number.
     * @param BigDecimal $y The second decimal number.
     * @param string     $a A variable to store the scaled integer value of $x.
     * @param string     $b A variable to store the scaled integer value of $y.
     *
     * @return void
     */
    private function scaleValues(BigDecimal $x, BigDecimal $y, & $a, & $b)
    {
        $a = $x->value;
        $b = $y->value;

        if ($b !== '0' && $x->scale > $y->scale) {
            $b .= str_repeat('0', $x->scale - $y->scale);
        } elseif ($a !== '0' && $x->scale < $y->scale) {
            $a .= str_repeat('0', $y->scale - $x->scale);
        }
    }

    /**
     * @param integer $scale
     *
     * @return string
     */
    private function valueWithMinScale($scale)
    {
        $value = $this->value;

        if ($this->value !== '0' && $scale > $this->scale) {
            $value .= str_repeat('0', $scale - $this->scale);
        }

        return $value;
    }

    /**
     * Adds leading zeros if necessary to the unscaled value to represent the full decimal number.
     *
     * @return string
     */
    private function getUnscaledValueWithLeadingZeros()
    {
        $value = $this->value;
        $targetLength = $this->scale + 1;
        $negative = ($value[0] === '-');
        $length = strlen($value);

        if ($negative) {
            $length--;
        }

        if ($length >= $targetLength) {
            return $this->value;
        }

        if ($negative) {
            $value = substr($value, 1);
        }

        $value = str_pad($value, $targetLength, '0', STR_PAD_LEFT);

        if ($negative) {
            $value = '-' . $value;
        }

        return $value;
    }
}
