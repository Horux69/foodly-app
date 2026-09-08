<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;

/**
 * Linea de pedido con los precios congelados al momento de la venta
 * (principio no negociable 8): editar el producto despues no la toca.
 */
final class OrderItem
{
    /** @param OrderItemModifier[] $modifiers */
    public function __construct(
        public readonly string $id,
        public readonly string $menuItemId,
        public readonly string $nameSnapshot,
        public readonly int $quantity,
        public readonly int $unitPriceCents,
        public readonly int $taxAmountCents,
        public readonly int $lineTotalCents,
        public readonly ?string $notes,
        public readonly array $modifiers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param OrderItemModifier[] $modifiers
     */
    public static function fromRow(array $row, array $modifiers = []): self
    {
        return new self(
            id: $row['id'],
            menuItemId: $row['menu_item_id'],
            nameSnapshot: $row['name_snapshot'],
            quantity: (int) $row['quantity'],
            unitPriceCents: Money::fromDecimalString((string) $row['unit_price']),
            taxAmountCents: Money::fromDecimalString((string) $row['tax_amount']),
            lineTotalCents: Money::fromDecimalString((string) $row['line_total']),
            notes: $row['notes'],
            modifiers: $modifiers,
        );
    }
}
