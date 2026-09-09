<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Uno de los productos que llevaba un combo cuando se vendio.
 *
 * Se congela igual que el nombre y el precio de la linea (principio 8): si
 * manana el combo cambia de contenido, la comanda de un pedido viejo tiene
 * que seguir diciendo lo que se preparo entonces.
 */
final class OrderItemComponent
{
    public function __construct(
        public readonly string $menuItemId,
        public readonly string $nameSnapshot,
        public readonly int $quantity,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            menuItemId: $row['menu_item_id'],
            nameSnapshot: $row['name_snapshot'],
            quantity: (int) $row['quantity'],
        );
    }
}
