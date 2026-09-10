<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Money;

/**
 * Cuanto de un cobro se puede devolver todavia.
 *
 * Pura y testeable: recibe el importe del cobro y lo que ya se le reembolso,
 * y decide. Es una pregunta distinta de la que responde PaymentBalance —esa
 * mira el pedido entero, esta un cobro concreto— y por eso vive aparte.
 *
 * La regla es la que espera cualquiera que haya trabajado en una caja: no se
 * puede devolver mas de lo que entro por ese cobro. Sumada varias veces en
 * reembolsos parciales, tampoco.
 */
final class RefundRules
{
    private function __construct()
    {
    }

    /**
     * Lo que queda por devolver de un cobro.
     *
     * @param int[] $alreadyRefundedCents importes de los reembolsos previos
     */
    public static function refundableCents(int $paymentCents, array $alreadyRefundedCents): int
    {
        return max(0, $paymentCents - (int) array_sum($alreadyRefundedCents));
    }

    /**
     * @param int[] $alreadyRefundedCents
     * @throws RefundError si el importe no cabe en lo que queda por devolver
     */
    public static function validate(int $paymentCents, array $alreadyRefundedCents, int $requestedCents): void
    {
        if ($requestedCents <= 0) {
            throw new RefundError('El reembolso debe ser mayor que cero');
        }

        $refundable = self::refundableCents($paymentCents, $alreadyRefundedCents);
        if ($refundable === 0) {
            throw new RefundError('Ese cobro ya se reembolso por completo');
        }
        if ($requestedCents > $refundable) {
            throw new RefundError(sprintf(
                'No se puede reembolsar %s: de ese cobro solo quedan %s por devolver',
                Money::toDecimalString($requestedCents),
                Money::toDecimalString($refundable),
            ));
        }
    }
}
