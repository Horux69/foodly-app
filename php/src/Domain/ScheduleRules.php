<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Que una franja horaria se pueda cumplir, y que la configuracion entera deje
 * vender por los canales que el restaurante dice tener.
 *
 * BranchSchedule responde "esta abierto ahora"; esta clase responde las dos
 * preguntas de antes: si la franja que se va a guardar tiene sentido, y si el
 * conjunto deja algun canal sin ninguna hora — que es la forma silenciosa de
 * apagar las ventas de un canal entero.
 */
final class ScheduleRules
{
    /** Lunes=0..Domingo=6, la misma convencion que branch_schedules. */
    public const DIAS = [
        0 => 'Lunes',
        1 => 'Martes',
        2 => 'Miercoles',
        3 => 'Jueves',
        4 => 'Viernes',
        5 => 'Sabado',
        6 => 'Domingo',
    ];

    private const HORA = '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';

    /** Normaliza "18:00" a "18:00:00", que es como se compara y como lo guarda TIME. */
    public static function normalizeTime(string $raw): string
    {
        $valor = trim($raw);
        if (preg_match(self::HORA, $valor) !== 1) {
            throw new ScheduleError("Hora invalida: '{$raw}'. Se espera HH:MM, como 18:00");
        }
        return strlen($valor) === 5 ? $valor . ':00' : $valor;
    }

    /**
     * @param string[] $canalesValidos los que la plataforma entiende
     */
    public static function validate(int $weekday, string $opensAt, string $closesAt, ?string $channel, array $canalesValidos): void
    {
        if (!array_key_exists($weekday, self::DIAS)) {
            throw new ScheduleError('El dia debe estar entre 0 (lunes) y 6 (domingo)');
        }
        if ($channel !== null && !in_array($channel, $canalesValidos, true)) {
            throw new ScheduleError(
                "Canal desconocido: {$channel}. Validos: " . implode(', ', $canalesValidos) . ', o ninguno para todos'
            );
        }

        // Una franja que abre y cierra a la misma hora no abre nunca en la
        // practica, y guardarla se parece demasiado a haber configurado algo.
        if ($opensAt === $closesAt) {
            throw new ScheduleError('La franja no puede abrir y cerrar a la misma hora');
        }
    }

    /**
     * Los canales que, con estos horarios, no pueden pedir en ningun momento.
     *
     * Una sucursal sin ninguna franja no restringe nada (asi no se bloquea a
     * quien todavia no termino de configurarse), pero en cuanto hay una, todo
     * canal sin cobertura queda cerrado siempre. Es facil de provocar —se
     * configuran las horas del mostrador y se olvida el domicilio— y no da
     * ninguna senal hasta que alguien intenta vender.
     *
     * @param ScheduleWindow[] $windows
     * @param string[] $canalesActivos los que el tenant declara en su configuracion
     * @return string[]
     */
    public static function channelsWithoutWindows(array $windows, array $canalesActivos): array
    {
        $activas = array_filter($windows, static fn (ScheduleWindow $w) => $w->isActive);
        if ($activas === []) {
            return [];
        }

        // Una franja sin canal cubre a todos: con una sola, nadie queda fuera.
        foreach ($activas as $window) {
            if ($window->channel === null) {
                return [];
            }
        }

        $cubiertos = array_map(static fn (ScheduleWindow $w) => $w->channel, $activas);
        return array_values(array_diff($canalesActivos, $cubiertos));
    }
}
