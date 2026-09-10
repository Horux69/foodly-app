<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Money;

/**
 * La propina, que se decide al pagar y no al pedir.
 *
 * Hasta ahora viajaba en el cuerpo de POST /orders y no se podia tocar
 * despues: habia que adivinarla antes de que el cliente pagara. Pero la
 * propina se define cuando se cobra —la del dataphono, la que el cliente
 * redondea, la que deja en efectivo—, y en Colombia es voluntaria: quitarla
 * tiene que ser tan facil como ponerla.
 *
 * Dos reglas, las dos del lado de no cobrar de mas:
 *
 * - **Cero siempre es valido.** No es un caso raro que haya que justificar.
 * - **No mas del 100% de la venta.** Una propina mayor que el pedido casi
 *   siempre es un error de digitacion —20.000 donde iban 2.000— y el momento
 *   de descubrirlo no es el arqueo.
 */
final class TipRules
{
    /** Tope de cordura: por encima, casi seguro sobra un cero. */
    public const MAX_PORCENTAJE = 100.0;

    private function __construct()
    {
    }

    /** @throws TipError */
    public static function validate(int $tipCents, int $subtotalCents): void
    {
        if ($tipCents < 0) {
            throw new TipError('La propina no puede ser negativa');
        }
        if ($subtotalCents > 0 && $tipCents > $subtotalCents) {
            throw new TipError(sprintf(
                'Una propina de %s sobre una venta de %s: revisa el importe',
                Money::toDecimalString($tipCents),
                Money::toDecimalString($subtotalCents),
            ));
        }
    }

    /** Lo que se sugiere, redondeado hacia abajo al peso. */
    public static function suggestCents(int $subtotalCents, float $percent): int
    {
        if ($percent <= 0) {
            return 0;
        }
        return (int) floor($subtotalCents * $percent / 100);
    }
}
