<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dinero en centavos enteros (int), nunca float ni bcmath: evita depender de
 * una extension que no todos los hosts tienen habilitada (este mismo entorno
 * de pruebas no la trae), y un entero no arrastra el error de redondeo
 * binario que un float si arrastra. Equivalente a NUMERIC(10,2) en Postgres.
 */
final class Money
{
    private function __construct()
    {
    }

    /** "12.34" -> 1234. No pasa por float en ningun punto de la conversion. */
    public static function fromDecimalString(string $value): int
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }

        [$intPart, $fracPart] = array_pad(explode('.', $value, 2), 2, '0');
        $intPart = $intPart === '' ? '0' : $intPart;
        $fracPart = str_pad(substr($fracPart, 0, 2), 2, '0');

        $cents = ((int) $intPart) * 100 + (int) $fracPart;
        return $negative ? -$cents : $cents;
    }

    /** 1234 -> "12.34" */
    public static function toDecimalString(int $cents): string
    {
        $negative = $cents < 0;
        $abs = abs($cents);
        $formatted = sprintf('%d.%02d', intdiv($abs, 100), $abs % 100);
        return $negative ? "-{$formatted}" : $formatted;
    }

    /**
     * Redondeo half-up (las mitades se alejan de cero), igual que
     * decimal.ROUND_HALF_UP en la version Python de este mismo calculo.
     * Los valores que entran aqui son montos en centavos ya acotados
     * (cantidad * precio, fraccion de un impuesto de un digito): no hay
     * riesgo real de precision de punto flotante a esta escala.
     */
    public static function roundHalfUp(float $value): int
    {
        return (int) ($value >= 0 ? floor($value + 0.5) : ceil($value - 0.5));
    }
}
