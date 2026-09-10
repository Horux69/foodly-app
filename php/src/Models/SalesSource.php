<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

/** Un origen de venta del tenant: de donde vino el pedido y cuanto cuesta. */
final class SalesSource
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $name,
        public readonly float $commissionPercent,
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
            commissionPercent: (float) $row['commission_percent'],
            isActive: Row::bool($row['is_active']),
        );
    }
}
