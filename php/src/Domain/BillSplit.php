<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Repartir una cuenta entre varias personas.
 *
 * Pura y testeable. Existe por una sola razon y es el centavo suelto: 41000
 * entre tres no da un numero redondo, y si cada parte se redondea por su
 * cuenta la suma no vuelve a dar 41000. El pedido quedaria con un centavo
 * pendiente para siempre —nunca saldado, apareciendo en la caja de todos los
 * turnos siguientes— o cobrado de mas.
 *
 * Por eso el reparto se calcula aqui, en centavos enteros, y no en el
 * navegador con dos divisiones y un `toFixed(2)`.
 */
final class BillSplit
{
    /**
     * Mas partes que esto no es dividir una cuenta, es un error de tecleo.
     * El limite existe para que un `parts=1000000` no arme un arreglo de un
     * millon de centavos en memoria.
     */
    public const MAX_PARTS = 50;

    private function __construct()
    {
    }

    /**
     * Reparte un importe en partes lo mas iguales posible que suman
     * exactamente el importe.
     *
     * El resto se reparte de a un centavo entre las primeras partes: con
     * 41000.00 entre tres salen 13666.67, 13666.67 y 13666.66. Que las
     * primeras paguen el centavo de mas es arbitrario, pero tiene que ser
     * una regla fija: lo que no puede pasar es que el centavo se pierda.
     *
     * @return int[] importes en centavos, tantos como partes
     */
    public static function equalParts(int $totalCents, int $parts): array
    {
        if ($parts < 1 || $parts > self::MAX_PARTS) {
            throw new BillSplitError('La cuenta se divide entre 1 y ' . self::MAX_PARTS . ' partes');
        }
        if ($totalCents <= 0) {
            throw new BillSplitError('No queda nada por cobrar de este pedido');
        }
        if ($totalCents < $parts) {
            throw new BillSplitError('No alcanza para tantas partes: no se puede cobrar menos de un centavo');
        }

        $base = intdiv($totalCents, $parts);
        $resto = $totalCents - $base * $parts;

        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $result[] = $base + ($i < $resto ? 1 : 0);
        }
        return $result;
    }
}
