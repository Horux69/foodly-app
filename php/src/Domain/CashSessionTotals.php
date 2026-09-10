<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * El cuadre de un turno de caja.
 *
 * Funcion pura: recibe la base con la que se abrio, los movimientos del turno
 * y lo que se conto al cerrar, y dice cuanto deberia haber y cuanto sobra o
 * falta. Es la unica fuente de verdad del arqueo; no repetir esta aritmetica
 * en el controlador ni en la pantalla.
 *
 * Tres cosas que se deciden aqui y conviene no deshacer:
 *
 * 1. **Solo el efectivo cuadra contra un conteo.** Lo cobrado con tarjeta o
 *    por transferencia no pasa por el cajon: se reporta por metodo, pero la
 *    diferencia se mide unicamente contra el efectivo. Cual metodo es el
 *    fisico se recibe como parametro y no se adivina, para que un dia que
 *    haya dos formas de efectivo esto no haya que reescribirlo.
 *
 * 2. **El cajon tambien recibe y entrega plata por fuera de las ventas.** La
 *    sangria, el pago al domiciliario, la compra de emergencia. Entran aqui
 *    como un movimiento mas (`DrawerMovement`) porque cambian lo que deberia
 *    haber; sin ellos el arqueo declara un faltante que no es un faltante.
 *
 * 3. **La diferencia se calcula, no se guarda.** Si manana se registra un
 *    reembolso de un cobro de este turno, el esperado cambia y con el la
 *    diferencia. Una cifra guardada al cerrar mentiria a partir de ese
 *    momento.
 */
final class CashSessionTotals
{
    public const CASH_METHOD = 'cash';

    /**
     * @param array<string, int> $netByMethod neto cobrado por metodo (cobros menos reembolsos)
     */
    public function __construct(
        public readonly int $openingFloatCents,
        public readonly array $netByMethod,
        public readonly int $chargedCents,
        public readonly int $refundedCents,
        /** Efectivo que entro al cajon sin ser una venta. */
        public readonly int $cashInCents,
        /** Efectivo que salio del cajon sin ser un reembolso. */
        public readonly int $cashOutCents,
        /** Lo que deberia haber en el cajon: base, neto en efectivo y los movimientos del cajon. */
        public readonly int $expectedCashCents,
        public readonly ?int $countedCashCents,
        /** Contado menos esperado: positivo sobra, negativo falta. Null si no se ha contado. */
        public readonly ?int $differenceCents,
    ) {
    }

    /**
     * @param CashMovement[] $movements cobros y reembolsos del turno
     * @param string $cashMethod cual de los metodos es plata fisica en el cajon
     * @param DrawerMovement[] $drawer entradas y salidas de efectivo que no son ventas
     */
    public static function compute(
        int $openingFloatCents,
        array $movements,
        ?int $countedCashCents = null,
        string $cashMethod = self::CASH_METHOD,
        array $drawer = [],
    ): self {
        $net = [];
        $charged = 0;
        $refunded = 0;

        foreach ($movements as $movement) {
            $net[$movement->method] ??= 0;
            if ($movement->isRefund) {
                $net[$movement->method] -= $movement->amountCents;
                $refunded += $movement->amountCents;
            } else {
                $net[$movement->method] += $movement->amountCents;
                $charged += $movement->amountCents;
            }
        }

        // Orden estable para que la pantalla no reordene columnas entre
        // refrescos segun el orden en que hayan entrado los cobros.
        ksort($net);

        $entradas = 0;
        $salidas = 0;
        foreach ($drawer as $movimiento) {
            if ($movimiento->esSalida()) {
                $salidas += $movimiento->amountCents;
            } else {
                $entradas += $movimiento->amountCents;
            }
        }

        $expectedCash = $openingFloatCents + ($net[$cashMethod] ?? 0) + $entradas - $salidas;

        return new self(
            openingFloatCents: $openingFloatCents,
            netByMethod: $net,
            chargedCents: $charged,
            refundedCents: $refunded,
            cashInCents: $entradas,
            cashOutCents: $salidas,
            expectedCashCents: $expectedCash,
            countedCashCents: $countedCashCents,
            differenceCents: $countedCashCents === null ? null : $countedCashCents - $expectedCash,
        );
    }

    /** Lo cobrado en total, ya descontados los reembolsos. */
    public function netCollectedCents(): int
    {
        return $this->chargedCents - $this->refundedCents;
    }
}
