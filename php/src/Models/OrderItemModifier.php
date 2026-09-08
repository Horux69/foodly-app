<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;

/** Modificador congelado en la linea del pedido: nombre y recargo del momento de la venta. */
final class OrderItemModifier
{
    public function __construct(
        public readonly string $modifierId,
        public readonly string $nameSnapshot,
        public readonly int $priceDeltaCents,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            modifierId: $row['modifier_id'],
            nameSnapshot: $row['name_snapshot'],
            priceDeltaCents: Money::fromDecimalString((string) $row['price_delta']),
        );
    }
}
