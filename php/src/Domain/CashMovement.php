<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Un movimiento de plata dentro de un turno de caja.
 *
 * El importe siempre es positivo; lo que decide el signo es `isRefund`, igual
 * que en la tabla payments. Se pasa asi y no con un entero con signo para que
 * el dominio no tenga que confiar en que alguien recordo negarlo.
 */
final class CashMovement
{
    public function __construct(
        public readonly string $method,
        public readonly int $amountCents,
        public readonly bool $isRefund = false,
    ) {
    }
}
