<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Money;

/**
 * Calculo de totales de un pedido, en centavos enteros.
 *
 * Funcion pura y testeable: no toca base de datos ni framework. Es la UNICA
 * fuente de verdad para los totales; nunca duplicar esta aritmetica en
 * controladores o en el frontend.
 */
final class OrderTotalsCalculator
{
    public static function computeLine(LineInput $line): LineResult
    {
        $unit = $line->unitPriceCents + array_sum($line->modifierDeltasCents);
        $gross = $unit * $line->quantity;

        if ($line->taxRate === 0.0) {
            return new LineResult($gross, 0);
        }

        if ($line->taxIncludedInPrice) {
            // El precio ya trae el impuesto: se extrae para reportarlo.
            $base = $gross / (1 + $line->taxRate);
            $tax = Money::roundHalfUp($gross - $base);
            return new LineResult($gross, $tax);
        }

        $tax = Money::roundHalfUp($gross * $line->taxRate);
        return new LineResult($gross + $tax, $tax);
    }

    /**
     * @param LineInput[] $lines
     */
    public static function computeTotals(
        array $lines,
        int $deliveryFeeCents = 0,
        int $discountCents = 0,
        int $tipCents = 0,
    ): OrderTotals {
        return self::totalsFromLines(
            array_map(self::computeLine(...), $lines),
            $deliveryFeeCents,
            $discountCents,
            $tipCents,
        );
    }

    /**
     * Los totales a partir de lineas ya calculadas.
     *
     * Existe para editar un pedido. Sus lineas viejas tienen el precio y el
     * impuesto congelados del momento de la venta (principio 8) y no se
     * vuelven a calcular: se recalcula el total del pedido, que es la suma de
     * lo que hay ahora. Asi la aritmetica sigue viviendo en un solo lugar sin
     * tener que reconstruir un LineInput a partir de una fila congelada
     * —cuyo impuesto pudo cambiar de tarifa desde entonces—.
     *
     * @param LineResult[] $results
     */
    public static function totalsFromLines(
        array $results,
        int $deliveryFeeCents = 0,
        int $discountCents = 0,
        int $tipCents = 0,
    ): OrderTotals {
        $subtotal = (int) array_sum(array_map(static fn (LineResult $r) => $r->lineTotalCents, $results));
        $taxTotal = (int) array_sum(array_map(static fn (LineResult $r) => $r->taxAmountCents, $results));
        $total = $subtotal + $deliveryFeeCents - $discountCents + $tipCents;

        if ($total < 0) {
            throw new OrderTotalsError('El total del pedido no puede ser negativo');
        }

        return new OrderTotals($subtotal, $taxTotal, $deliveryFeeCents, $discountCents, $tipCents, $total, $results);
    }
}
