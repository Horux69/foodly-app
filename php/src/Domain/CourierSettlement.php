<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Money;

/**
 * El cuadre de un repartidor: cuanto deberia traer y cuanto trajo.
 *
 * Es el arqueo aplicado a otra caja. El repartidor sale con pedidos que se
 * cobran contra entrega y vuelve con efectivo que hasta ahora nadie cuadraba
 * contra nada: la diferencia aparecia —si aparecia— en el arqueo del cajon,
 * donde ya no se puede decir de que pedido salio.
 *
 * Sigue las mismas dos reglas que `CashSessionTotals`, y por los mismos
 * motivos:
 *
 * - **Solo el efectivo se cuadra.** Lo que el cliente pago con tarjeta o
 *   transferencia no pasa por las manos del repartidor. Cual metodo es el
 *   fisico se recibe, no se adivina.
 * - **La diferencia se calcula, no se guarda.** Si manana se reembolsa uno
 *   de esos pedidos, el esperado cambia; una cifra guardada al cuadrar
 *   mentiria a partir de ese momento.
 *
 * Y una propia: **un reembolso de un pedido que llevo el repartidor le baja
 * lo que debe traer.** La plata que se le devolvio al cliente no volvio al
 * restaurante, y exigirsela seria cobrarle dos veces.
 */
final class CourierSettlement
{
    private function __construct()
    {
    }

    /**
     * Lo que deberia traer: cobrado en efectivo menos lo reembolsado.
     *
     * @param int $chargedCents cobros en efectivo de sus pedidos
     * @param int $refundedCents reembolsos en efectivo de esos mismos pedidos
     */
    public static function expectedCents(int $chargedCents, int $refundedCents): int
    {
        return $chargedCents - $refundedCents;
    }

    /** Contado menos esperado: positivo sobra, negativo falta. */
    public static function differenceCents(int $countedCents, int $expectedCents): int
    {
        return $countedCents - $expectedCents;
    }

    /** @throws CourierSettlementError */
    public static function ensureCounted(int $countedCents): void
    {
        if ($countedCents < 0) {
            throw new CourierSettlementError('Lo entregado no puede ser negativo');
        }
    }

    /**
     * Cuadrar a alguien que no debe nada y no entrega nada es una fila que
     * no dice nada, y ademas cierra la ventana: el cobro que entre un minuto
     * despues quedaria del lado ya cuadrado.
     *
     * @throws CourierSettlementError
     */
    public static function ensureHayQueCuadrar(int $expectedCents, int $countedCents): void
    {
        if ($expectedCents === 0 && $countedCents === 0) {
            throw new CourierSettlementError('Este repartidor no tiene efectivo pendiente: no hay nada que cuadrar');
        }
    }

    /**
     * Como se lee una diferencia, para la bitacora y la pantalla.
     */
    public static function describe(int $differenceCents): string
    {
        if ($differenceCents === 0) {
            return 'Cuadra exacto';
        }
        return $differenceCents > 0
            ? 'Sobran ' . Money::toDecimalString($differenceCents)
            : 'Faltan ' . Money::toDecimalString(-$differenceCents);
    }
}
