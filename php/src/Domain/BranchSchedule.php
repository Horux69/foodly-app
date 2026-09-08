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
            if (!$window->isActive || $window->weekday !== $weekday) {
                continue;
            }
            if ($window->channel !== null && $window->channel !== $channel) {
                continue;
            }
            if ($window->opensAt <= $at && $at <= $window->closesAt) {
                return true;
            }
        }
        return false;
    }
}
