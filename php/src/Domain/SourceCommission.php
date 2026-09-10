<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Lo que se queda el agregador, y lo que le queda al restaurante.
 *
 * Un pedido de Rappi se prepara igual y se cobra igual, pero no vale lo
 * mismo: hoy el reporte dice que se vendieron 50.000 cuando entraron
 * 35.000. Con dos o tres plataformas, esa diferencia es la que decide si el
 * negocio gana.
 *
 * Dos decisiones:
 *
 * - **El porcentaje se congela con la venta**, como el precio (principio
 *   8). El pedido guarda de que origen vino y con que comision se vendio; si
 *   se leyera del origen cada vez, renegociar el contrato con la plataforma
 *   reescribiria cuanto se gano en marzo. La cifra de plata, en cambio, se
 *   calcula: es aritmetica sobre un total que puede cambiar mientras la
 *   cuenta este abierta.
 * - **Se calcula sobre el total, no sobre el subtotal.** Es como cobran las
 *   plataformas: sobre lo que el cliente pago, domicilio incluido.
 */
final class SourceCommission
{
    private function __construct()
    {
    }

    /** @throws SourceCommissionError */
    public static function ensureValid(float $percent): void
    {
        if ($percent < 0 || $percent > 100) {
            throw new SourceCommissionError('La comision va entre 0 y 100 por ciento');
        }
    }

    /**
     * Lo que se lleva la plataforma, en centavos.
     *
     * Redondeo al centavo mas cercano, con el medio hacia arriba: es lo que
     * hace una factura, y truncar dejaria al restaurante reportando de menos
     * cada pedido.
     */
    public static function feeCents(int $totalCents, float $percent): int
    {
        if ($percent <= 0 || $totalCents <= 0) {
            return 0;
        }
        return (int) round($totalCents * $percent / 100);
    }

    /** Lo que le queda al restaurante. */
    public static function netCents(int $totalCents, float $percent): int
    {
        return $totalCents - self::feeCents($totalCents, $percent);
    }
}
