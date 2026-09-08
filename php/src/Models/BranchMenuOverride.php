<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;
use App\Core\Row;

/**
 * Precio/disponibilidad diferenciados por sucursal.
 *
 * Resolver el precio efectivo SIEMPRE via Domain\MenuPricing; nunca comparar
 * un `price === null` suelto en otras capas.
 */
final class BranchMenuOverride
{
    public function __construct(
        public readonly string $branchId,
        public readonly string $menuItemId,
        public readonly ?int $priceCents,
        public readonly ?bool $isAvailable,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            branchId: $row['branch_id'],
            menuItemId: $row['menu_item_id'],
            priceCents: $row['price'] === null ? null : Money::fromDecimalString((string) $row['price']),
            isAvailable: Row::nullableBool($row['is_available']),
        );
    }
}
