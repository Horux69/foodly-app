<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Que contrasena se acepta al cambiarla.
 *
 * Un minimo de longitud y poco mas: las reglas de composicion (una mayuscula,
 * un simbolo) empujan a la gente a "Clave123!" y a escribirla en un papel
 * pegado al monitor, que en un mostrador compartido es exactamente lo que hay
 * que evitar. Largo es lo que sirve.
 */
final class PasswordRules
{
    public const MINIMO = 8;

    /** El hash de bcrypt trunca en 72 bytes: mas alla, los caracteres no cuentan. */
    public const MAXIMO = 72;

    public static function validate(string $nueva, ?string $actual = null): void
    {
        $largo = strlen($nueva); // en bytes, que es lo que bcrypt cuenta
        if ($largo < self::MINIMO) {
            throw new PasswordError('La contrasena nueva necesita al menos ' . self::MINIMO . ' caracteres');
        }
        if ($largo > self::MAXIMO) {
            throw new PasswordError('La contrasena nueva no puede pasar de ' . self::MAXIMO . ' caracteres');
        }
        if (trim($nueva) === '') {
            throw new PasswordError('La contrasena nueva no puede ser solo espacios');
        }
        if ($actual !== null && $nueva === $actual) {
            throw new PasswordError('La contrasena nueva tiene que ser distinta de la actual');
        }
    }
}
