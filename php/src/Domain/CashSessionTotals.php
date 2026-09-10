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
 * Dos cosas que se deciden aqui y conviene no deshacer:
 *
 * 1. **Solo el efectivo cuadra contra un conteo.** Lo cobrado con tarjeta o
 *    por transferencia no pasa por el cajon: se reporta por metodo, pero la
 *    diferencia se mide unicamente contra el efectivo. Cual metodo es el
 *    fisico se recibe como parametro y no se adivina, para que un dia que
 *    haya dos formas de efectivo esto no haya que reescribirlo.
 *
 * 2. **La diferencia se calcula, no se guarda.** Si manana se registra un
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
        /** Lo que deberia haber en el cajon: base mas el neto en efectivo. */
        public readonly int $expectedCashCents,
        public readonly ?int $countedCashCents,
        /** Contado menos esperado: positivo sobra, negativo falta. Null si no se ha contado. */
        public readonly ?int $differenceCents,
    ) {
    }

    /**
     * @param CashMovement[] $movements
     * @param string $cashMethod cual de los metodos es plata fisica en el cajon
     */
    public static function compute(
        int $openingFloatCents,
        array $movements,
        ?int $countedCashCents = null,
        string $cashMethod = self::CASH_METHOD,
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

        $expectedCash = $openingFloatCents + ($net[$cashMethod] ?? 0);

        return new self(
            openingFloatCents: $openingFloatCents,
            netByMethod: $net,
            chargedCents: $charged,
            refundedCents: $refunded,
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
