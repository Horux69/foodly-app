<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Money;

/**
 * Cuanto se puede cobrar de un pedido.
 *
 * El espejo de RefundRules: aquella dice cuanto se puede devolver de un
 * cobro, esta cuanto se puede cobrar de un pedido. Y la respuesta es la
 * misma que daria cualquiera en una caja: lo que falte, ni un peso mas.
 *
 * Sin esta regla, tecleado un 200000 donde iban 20000 el pedido queda
 * "saldado" con el saldo en negativo, la plata entra al arqueo y el cajon
 * cuadra de mas sin que nada diga por que. Un vuelto no se registra como
 * cobro: se entrega.
 */
final class ChargeRules
{
    private function __construct()
    {
    }

    public static function validate(PaymentBalance $balance, int $requestedCents): void
    {
        if ($requestedCents <= 0) {
            throw new ChargeError('El cobro debe ser mayor que cero');
        }
        if ($balance->pendingCents <= 0) {
            throw new ChargeError('El pedido ya esta saldado: no queda nada por cobrar');
        }
        if ($requestedCents > $balance->pendingCents) {
            throw new ChargeError(sprintf(
                'No se puede cobrar %s: al pedido solo le faltan %s',
                Money::toDecimalString($requestedCents),
                Money::toDecimalString($balance->pendingCents),
            ));
        }
    }
}
