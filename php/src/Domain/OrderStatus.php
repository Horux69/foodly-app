<?php

declare(strict_types=1);

namespace App\Domain;

final class OrderStatus
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $category,
        public readonly bool $isInitial,
        public readonly bool $isFinal,
    ) {
    }
}
