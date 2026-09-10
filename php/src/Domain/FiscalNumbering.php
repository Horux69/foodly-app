<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * El consecutivo autorizado de una resolucion.
 *
 * Funcion pura: recibe el rango, lo ya usado y la vigencia, y dice cual es
 * el numero siguiente. Es la pieza que existe aunque no haya proveedor
 * tecnologico conectado, porque el papel que se le entrega al cliente ya
 * lleva el numero.
 *
 * Las tres razones para negarse son las tres que la autoridad castiga:
 * numerar fuera del rango, numerar despues de vencida la resolucion y
 * repetir un consecutivo.
 */
final class FiscalNumbering
{
    /** Cuando avisar de que el rango se esta acabando. */
    public const AVISO_RESTANTES = 100;

    private function __construct()
    {
    }

    /**
     * @param string|null $validUntil fecha ISO (YYYY-MM-DD); null es sin vencimiento
     * @param string|null $hoy la fecha con la que comparar, para poder probarlo
     * @throws FiscalError
     */
    public static function next(
        int $rangeFrom,
        int $rangeTo,
        int $currentNumber,
        ?string $validUntil = null,
        ?string $hoy = null,
    ): int {
        $hoy ??= (new \DateTimeImmutable('now'))->format('Y-m-d');

        if ($validUntil !== null && $hoy > $validUntil) {
            throw new FiscalError(
                "La resolucion vencio el {$validUntil}. Pide una nueva antes de seguir facturando."
            );
        }

        $siguiente = max($currentNumber + 1, $rangeFrom);
        if ($siguiente > $rangeTo) {
            throw new FiscalError(
                "Se acabo el rango autorizado ({$rangeFrom}-{$rangeTo}). Pide una resolucion nueva."
            );
        }

        return $siguiente;
    }

    /** Cuantos consecutivos quedan. Es lo que se muestra antes de que sea tarde. */
    public static function restantes(int $rangeTo, int $currentNumber): int
    {
        return max(0, $rangeTo - $currentNumber);
    }

    public static function porAcabarse(int $rangeTo, int $currentNumber): bool
    {
        return self::restantes($rangeTo, $currentNumber) <= self::AVISO_RESTANTES;
    }

    /** Como se lee el numero completo: el prefijo pegado al consecutivo. */
    public static function format(string $prefix, int $number): string
    {
        return $prefix . $number;
    }
}
