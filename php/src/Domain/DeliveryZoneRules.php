<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * La zona vista por el dominio: solo lo que hace falta para decidir si el
 * pedido se puede despachar y cuanto cuesta llevarlo. Sin id, sin sucursal y
 * sin PDO, para que la regla se pueda probar sin base de datos.
 */
final class DeliveryZoneRules
{
    public function __construct(
        public readonly string $name,
        public readonly int $feeCents,
        public readonly int $minOrderCents,
        public readonly ?int $estMinutes = null,
    ) {
    }
}
