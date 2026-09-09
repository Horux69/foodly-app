<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Hasta cuando se puede seguir renovando una sesion.
 *
 * Renovar existe porque el token dura ocho horas y un turno puede ser mas
 * largo: sin renovacion, a alguien se le cierra la sesion en mitad de un
 * pedido. Pero renovar sin limite convierte un token robado en permanente, y
 * la unica forma de cortarlo seria rotar SECRET_KEY, que echa a todo el
 * mundo.
 *
 * El limite se mide desde que la persona escribio su contrasena
 * (`auth_time`, que se arrastra de un token al siguiente) y no desde la
 * emision del ultimo: si no, renovar correria el limite para siempre y no
 * seria un limite.
 */
final class SessionRenewal
{
    public const MAX_HORAS = 24;

    public static function ensureRenewable(int $authTime, int $now, int $maxHoras = self::MAX_HORAS): void
    {
        if ($now - $authTime >= $maxHoras * 3600) {
            throw new SessionError('La sesion lleva demasiado tiempo abierta: vuelve a entrar');
        }
    }
}
