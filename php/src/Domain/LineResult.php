<?php

declare(strict_types=1);

namespace App\Domain;

final class LineResult
{
    public function __construct(
        public readonly int $lineTotalCents,
        public readonly int $taxAmountCents,
    ) {
    }
}
