<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Saldo de un pedido frente a sus pagos.
 *
 * Funcion pura: recibe el total del pedido, los montos ya cobrados y los
 * devueltos (en centavos), y dice cuanto falta. Es la UNICA fuente de verdad
 * para decidir si un pedido esta saldado; no duplicar esta resta en
 * controladores ni en el frontend.
 *
 * `paidCents` es lo que entro y `refundedCents` lo que salio; `netPaidCents`
 * es lo que de verdad tiene el pedido encima. Se exponen los tres y no solo
 * el neto porque la caja necesita las dos cifras: un pedido cobrado y
 * devuelto no es lo mismo que uno que nunca se cobro, aunque su saldo sea
 * igual.
 */
final class PaymentBalance
{
    public function __construct(
        public readonly int $totalCents,
        public readonly int $paidCents,
        public readonly int $pendingCents,
        public readonly bool $isSettled,
        public readonly int $refundedCents = 0,
        public readonly int $netPaidCents = 0,
    ) {
    }

    /**
     * @param int[] $paidAmountsCents
     * @param int[] $refundedAmountsCents importes devueltos, en positivo
     */
    public static function compute(
        int $orderTotalCents,
        array $paidAmountsCents,
        array $refundedAmountsCents = [],
    ): self {
        $paid = (int) array_sum($paidAmountsCents);
        $refunded = (int) array_sum($refundedAmountsCents);
        $net = $paid - $refunded;

        return new self(
            totalCents: $orderTotalCents,
            paidCents: $paid,
            pendingCents: $orderTotalCents - $net,
            isSettled: $net >= $orderTotalCents,
            refundedCents: $refunded,
            netPaidCents: $net,
        );
    }
}
