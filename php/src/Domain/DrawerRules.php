<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Money;

/**
 * Que un movimiento de cajon sea posible.
 *
 * La regla que importa es la ultima: **no se puede sacar del cajon mas de lo
 * que hay**. Un cajon en negativo no existe, y dejarlo pasar convierte el
 * arqueo en una cifra que nadie puede explicar — que es como los controles
 * dejan de usarse.
 *
 * El motivo es obligatorio por lo mismo: la lista de movimientos existe para
 * responder "y estos 200.000, ¿en que se fueron?" al final del turno.
 */
final class DrawerRules
{
    public const MOTIVO_MAX = 120;

    private function __construct()
    {
    }

    /** @throws DrawerError */
    public static function validate(string $kind, int $amountCents, string $reason, int $efectivoEnCajaCents): void
    {
        if (!in_array($kind, DrawerMovement::TIPOS, true)) {
            throw new DrawerError("Tipo de movimiento no valido: '{$kind}'. Solo 'in' o 'out'.");
        }
        if ($amountCents <= 0) {
            throw new DrawerError('El movimiento debe ser mayor que cero');
        }
        if (trim($reason) === '') {
            throw new DrawerError('Di por que se movio la plata: sin motivo, el arqueo no lo puede explicar');
        }
        if (mb_strlen($reason) > self::MOTIVO_MAX) {
            throw new DrawerError('El motivo no puede pasar de ' . self::MOTIVO_MAX . ' caracteres');
        }
        if ($kind === DrawerMovement::SALIDA && $amountCents > $efectivoEnCajaCents) {
            throw new DrawerError(sprintf(
                'No se pueden sacar %s: en el cajon hay %s',
                Money::toDecimalString($amountCents),
                Money::toDecimalString($efectivoEnCajaCents),
            ));
        }
    }
}
