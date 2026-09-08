<?php

declare(strict_types=1);

namespace App\Domain;

final class EffectiveMenuItem
{
    public function __construct(
        public readonly int $priceCents,
        public readonly bool $isAvailable,
    ) {
    }
}
