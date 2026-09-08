<?php

declare(strict_types=1);

namespace App\Domain;

final class LineInput
{
    /** @param int[] $modifierDeltasCents */
    public function __construct(
        public readonly int $quantity,
        public readonly int $unitPriceCents,
        public readonly float $taxRate = 0.0,
        public readonly bool $taxIncludedInPrice = true,
        public readonly array $modifierDeltasCents = [],
    ) {
    }
}
