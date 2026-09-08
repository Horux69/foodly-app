<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Saldo de un pedido frente a sus pagos.
 *
 * Funcion pura: recibe el total del pedido y los montos ya cobrados (en
 * centavos), y dice cuanto falta. Es la UNICA fuente de verdad para decidir
 * si un pedido esta saldado; no duplicar esta resta en controladores ni en
 * el frontend.
 */
final class PaymentBalance
{
    public function __construct(
        public readonly int $totalCents,
        public readonly int $paidCents,
        public readonly int $pendingCents,
        public readonly bool $isSettled,
    ) {
    }

    /** @param int[] $paidAmountsCents */
    public static function compute(int $orderTotalCents, array $paidAmountsCents): self
    {
        $paid = (int) array_sum($paidAmountsCents);
        $pending = $orderTotalCents - $paid;
        return new self($orderTotalCents, $paid, $pending, $paid >= $orderTotalCents);
    }
}
