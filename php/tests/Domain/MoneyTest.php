<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Core\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testIdaYVueltaDecimal(): void
    {
        $this->assertSame(1234, Money::fromDecimalString('12.34'));
        $this->assertSame('12.34', Money::toDecimalString(1234));
    }

    public function testSinDecimalesAsumeCero(): void
    {
        $this->assertSame(1800000, Money::fromDecimalString('18000'));
        $this->assertSame('18000.00', Money::toDecimalString(1800000));
    }

    public function testUnDecimalSeRellena(): void
    {
        $this->assertSame(1250, Money::fromDecimalString('12.5'));
    }

    public function testNegativos(): void
    {
        $this->assertSame(-800, Money::fromDecimalString('-8.00'));
        $this->assertSame('-8.00', Money::toDecimalString(-800));
    }

    public function testRedondeoMediaHaciaArriba(): void
    {
        $this->assertSame(13, Money::roundHalfUp(12.5));
        $this->assertSame(-13, Money::roundHalfUp(-12.5));
        $this->assertSame(12, Money::roundHalfUp(12.49));
    }
}
