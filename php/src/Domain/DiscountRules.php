<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Money;

/**
 * Que un descuento se pueda justificar.
 *
 * El descuento es la unica forma de que salga plata de una venta sin que
 * quede un cobro ni un reembolso que la respalde. Por eso las tres reglas no
 * son de aritmetica sino de control:
 *
 * - **Tiene motivo.** Sin el, el reporte de ajustes lista cifras que nadie
 *   puede explicar, que es no tener reporte.
 * - **No supera lo que se esta vendiendo.** Un descuento mayor que el
 *   subtotal no es un descuento, es regalar el domicilio y la propina de
 *   paso — y deja el total en negativo.
 * - **Cabe en el tope del rol.** Un cajero puede resolver un reclamo de
 *   5.000 sin llamar a nadie; el 100% lo autoriza otra persona. El tope es
 *   un porcentaje del subtotal y no un importe, porque lo que se perdona es
 *   una proporcion de la venta.
 */
final class DiscountRules
{
    private function __construct()
    {
    }

    /**
     * @param float|null $maxPercent tope del rol; null es sin tope
     * @throws DiscountError
     */
    public static function validate(
        int $discountCents,
        int $subtotalCents,
        ?string $reasonId,
        ?float $maxPercent,
    ): void {
        if ($discountCents <= 0) {
            throw new DiscountError('El descuento debe ser mayor que cero');
        }
        if ($reasonId === null || $reasonId === '') {
            throw new DiscountError('Elige el motivo del descuento: sin el, el reporte no lo puede explicar');
        }
        if ($discountCents > $subtotalCents) {
            throw new DiscountError(sprintf(
                'No se puede descontar %s de una venta de %s',
                Money::toDecimalString($discountCents),
                Money::toDecimalString($subtotalCents),
            ));
        }

        if ($maxPercent === null) {
            return;
        }
        $tope = self::topeCents($subtotalCents, $maxPercent);
        if ($discountCents > $tope) {
            throw new DiscountError(sprintf(
                'Tu rol puede descontar hasta el %s%% (%s). Este descuento necesita autorizacion.',
                rtrim(rtrim(number_format($maxPercent, 2, '.', ''), '0'), '.'),
                Money::toDecimalString($tope),
            ));
        }
    }

    /** Lo maximo que ese rol puede descontar de esa venta, redondeado hacia abajo. */
    public static function topeCents(int $subtotalCents, float $maxPercent): int
    {
        return (int) floor($subtotalCents * $maxPercent / 100);
    }

    /** Como se lee el descuento en la bitacora y en el ticket. */
    public static function describe(int $discountCents, int $subtotalCents, string $motivo): string
    {
        if ($subtotalCents <= 0) {
            return sprintf('Descuento de %s (%s)', Money::toDecimalString($discountCents), $motivo);
        }

        $porcentaje = round($discountCents * 100 / $subtotalCents, 1);
        return sprintf(
            'Descuento de %s (%s%%) por %s',
            Money::toDecimalString($discountCents),
            rtrim(rtrim(number_format($porcentaje, 1, '.', ''), '0'), '.'),
            $motivo,
        );
    }
}
