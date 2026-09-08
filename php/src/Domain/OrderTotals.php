<?php

declare(strict_types=1);

namespace App\Domain;

/** Resultado de OrderTotalsCalculator::computeTotals: valores en centavos enteros. */
final class OrderTotals
{
    /** @param LineResult[] $lines */
    public function __construct(
        public readonly int $subtotalCents,
        public readonly int $taxTotalCents,
        public readonly int $deliveryFeeCents,
        public readonly int $discountCents,
        public readonly int $tipCents,
        public readonly int $totalCents,
        public readonly array $lines,
    ) {
    }
}
