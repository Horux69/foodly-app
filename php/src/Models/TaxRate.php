<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

final class TaxRate
{
    /** @param string $rate fraccion decimal como string, p.ej. "0.0800" (NUMERIC(5,4)) */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $name,
        public readonly string $rate,
        public readonly bool $includedInPrice,
        public readonly bool $isDefault,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            name: $row['name'],
            rate: $row['rate'],
            includedInPrice: Row::bool($row['included_in_price']),
            isDefault: Row::bool($row['is_default']),
            isActive: Row::bool($row['is_active']),
        );
    }
}
