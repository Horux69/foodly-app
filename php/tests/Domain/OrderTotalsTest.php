<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Core\Money;
use App\Domain\LineInput;
use App\Domain\OrderTotalsCalculator;
use App\Domain\OrderTotalsError;
use PHPUnit\Framework\TestCase;

final class OrderTotalsTest extends TestCase
{
    public function testSubtotalConModificadores(): void
    {
        $lines = [new LineInput(quantity: 2, unitPriceCents: 1_800_000, modifierDeltasCents: [200_000])];
        $totals = OrderTotalsCalculator::computeTotals($lines);
        $this->assertSame('40000.00', Money::toDecimalString($totals->subtotalCents));
    }

    public function testImpuestoIncluidoEnPrecio(): void
    {
        $lines = [new LineInput(quantity: 1, unitPriceCents: 1_080_000, taxRate: 0.08, taxIncludedInPrice: true)];
        $totals = OrderTotalsCalculator::computeTotals($lines);
        $this->assertSame('10800.00', Money::toDecimalString($totals->subtotalCents));
        $this->assertSame('800.00', Money::toDecimalString($totals->taxTotalCents));
    }

    public function testImpuestoAgregadoAlPrecio(): void
    {
        $lines = [new LineInput(quantity: 1, unitPriceCents: 1_000_000, taxRate: 0.08, taxIncludedInPrice: false)];
        $totals = OrderTotalsCalculator::computeTotals($lines);
        $this->assertSame('800.00', Money::toDecimalString($totals->taxTotalCents));
        $this->assertSame('10800.00', Money::toDecimalString($totals->subtotalCents));
    }

    public function testDomicilioDescuentoYPropina(): void
    {
        $lines = [new LineInput(quantity: 1, unitPriceCents: 2_000_000)];
        $totals = OrderTotalsCalculator::computeTotals(
            $lines,
            deliveryFeeCents: 500_000,
            discountCents: 200_000,
            tipCents: 100_000,
        );
        $this->assertSame('24000.00', Money::toDecimalString($totals->totalCents));
    }

    public function testTotalNegativoFalla(): void
    {
        $this->expectException(OrderTotalsError::class);
        $lines = [new LineInput(quantity: 1, unitPriceCents: 500_000)];
        OrderTotalsCalculator::computeTotals($lines, discountCents: 1_000_000);
    }
}
