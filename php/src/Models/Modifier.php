<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;
use App\Core\Row;

final class Modifier
{
    public function __construct(
        public readonly string $id,
        public readonly string $groupId,
        public readonly string $name,
        public readonly int $priceDeltaCents,
        public readonly bool $isAvailable,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            groupId: $row['group_id'],
            name: $row['name'],
            priceDeltaCents: Money::fromDecimalString((string) $row['price_delta']),
            isAvailable: Row::bool($row['is_available']),
        );
    }
}
