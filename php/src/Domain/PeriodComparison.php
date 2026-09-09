<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * El periodo inmediatamente anterior, y cuanto cambio un numero contra el.
 *
 * Un dueno no lee "vendi 4 millones", lee "vendi 12% mas que la semana
 * pasada". El numero solo no dice si el mes fue bueno.
 */
final class PeriodComparison
{
    /**
     * El periodo de la misma cantidad de dias que termina justo antes de este.
     *
     * Del 1 al 7 se compara con el 25 al 31, no con "el mes pasado": comparar
     * siete dias con treinta daria una caida del 76% que no significa nada.
     *
     * @return array{0: string, 1: string} fechas YYYY-MM-DD
     */
    public static function previousRange(string $from, string $to): array
    {
        $inicio = new \DateTimeImmutable($from);
        $fin = new \DateTimeImmutable($to);

        // Inclusivo en los dos extremos: del 1 al 7 son siete dias.
        $dias = (int) $inicio->diff($fin)->days + 1;

        $finAnterior = $inicio->modify('-1 day');
        $inicioAnterior = $finAnterior->modify('-' . ($dias - 1) . ' days');

        return [$inicioAnterior->format('Y-m-d'), $finAnterior->format('Y-m-d')];
    }

    /**
     * Cuanto cambio, en porcentaje con un decimal.
     *
     * Devuelve null cuando antes no habia nada: dividir por cero daria
     * infinito, y "subio un infinito por ciento" no es una lectura. La
     * pantalla dice "sin base para comparar", que es lo que de verdad pasa.
     *
     * Se calcula sobre enteros (centavos, unidades) y el resultado no es
     * dinero: es un porcentaje, y ahi el float esta bien.
     */
    public static function change(int $antes, int $ahora): ?float
    {
        if ($antes === 0) {
            return null;
        }
        return round((($ahora - $antes) / $antes) * 100, 1);
    }
}
