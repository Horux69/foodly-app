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
    /**
     * @param OrderItemModifier[] $modifiers
     * @param OrderItemComponent[] $components lo que llevaba el combo; vacio si no lo es
     */
    public function __construct(
        public readonly string $id,
        public readonly string $menuItemId,
        public readonly string $nameSnapshot,
        public readonly int $quantity,
        public readonly int $unitPriceCents,
        /** La tarifa del momento de la venta, como fraccion decimal ("0.1900"). */
        public readonly string $taxRate,
        public readonly int $taxAmountCents,
        public readonly int $lineTotalCents,
        public readonly ?string $notes,
        public readonly array $modifiers = [],
        public readonly array $components = [],
        /** La categoria a la que pertenece hoy el producto: decide su estacion. */
        public readonly ?string $categoryId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param OrderItemModifier[] $modifiers
     * @param OrderItemComponent[] $components
     */
    public static function fromRow(array $row, array $modifiers = [], array $components = []): self
    {
        return new self(
            id: $row['id'],
            menuItemId: $row['menu_item_id'],
            nameSnapshot: $row['name_snapshot'],
            quantity: (int) $row['quantity'],
            unitPriceCents: Money::fromDecimalString((string) $row['unit_price']),
            taxRate: (string) $row['tax_rate'],
            taxAmountCents: Money::fromDecimalString((string) $row['tax_amount']),
            lineTotalCents: Money::fromDecimalString((string) $row['line_total']),
            notes: $row['notes'],
            modifiers: $modifiers,
            components: $components,
            categoryId: $row['category_id'] ?? null,
        );
    }
}
