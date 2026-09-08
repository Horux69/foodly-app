<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;
use App\Core\Row;

/**
 * Zona de reparto de una sucursal: cuanto cobra llevar y cuanto hay que pedir
 * como minimo.
 */
final class DeliveryZone
{
    public function __construct(
        public readonly string $id,
        public readonly string $branchId,
        public readonly string $name,
        public readonly int $feeCents,
        public readonly int $minOrderCents,
        public readonly ?int $estMinutes,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            branchId: $row['branch_id'],
            name: $row['name'],
            feeCents: Money::fromDecimalString((string) $row['fee']),
            minOrderCents: Money::fromDecimalString((string) $row['min_order']),
            estMinutes: $row['est_minutes'] === null ? null : (int) $row['est_minutes'],
            isActive: Row::bool($row['is_active']),
        );
    }
}
