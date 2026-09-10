<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Determina si una sucursal esta abierta para un canal en un momento dado.
 *
 * Pura y testeable: recibe el dia/hora ya resueltos (en la zona horaria de
 * la sucursal) y la lista de horarios configurados. No conoce zonas
 * horarias ni "ahora": eso lo resuelve quien la llama.
 *
 * Convencion de $weekday: igual que weekday() de Python (Monday=0..Sunday=6),
 * la misma que se asume al sembrar branch_schedules. PHP no tiene esa
 * convencion nativa: date('N') da 1(lunes)-7(domingo) y date('w') da
 * 0(domingo)-6(sabado) — quien llame a esta clase desde el servicio debe
 * convertir explicitamente, no asumir ninguna de las dos.
 */
final class BranchSchedule
{
    /**
     * @param string $at "HH:MM:SS"
     * @param ScheduleWindow[] $schedules
     */
    public static function isBranchOpen(int $weekday, string $at, string $channel, array $schedules): bool
    {
        foreach ($schedules as $window) {
            if (!$window->isActive) {
                continue;
            }
            // Un horario sin canal aplica a todos.
            if ($window->channel !== null && $window->channel !== $channel) {
                continue;
            }
            if (self::cubre($window, $weekday, $at)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Si la franja alcanza ese dia y esa hora.
     *
     * La que cierra antes de la hora a la que abre cruza la medianoche: el
     * viernes de 20:00 a 02:00 esta abierta el viernes desde las 20:00 y el
     * sabado hasta las 02:00. Sin esto, `opensAt <= $at && $at <= closesAt`
     * no se cumple nunca —ninguna hora es a la vez posterior a las 20:00 y
     * anterior a las 02:00— y un restaurante nocturno quedaria cerrado
     * siempre, sin ningun error a la vista.
     */
    private static function cubre(ScheduleWindow $window, int $weekday, string $at): bool
    {
        if ($window->opensAt <= $window->closesAt) {
            return $window->weekday === $weekday && $window->opensAt <= $at && $at <= $window->closesAt;
        }

        if ($window->weekday === $weekday) {
            return $at >= $window->opensAt;      // la noche del propio dia
        }
        return $window->weekday === self::diaAnterior($weekday) && $at <= $window->closesAt; // la madrugada siguiente
    }

    /** Lunes=0..Domingo=6, asi que el anterior al lunes es el domingo. */
    private static function diaAnterior(int $weekday): int
    {
        return ($weekday + 6) % 7;
    }
}
