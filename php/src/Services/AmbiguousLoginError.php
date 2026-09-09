<?php

declare(strict_types=1);

namespace App\Services;

/**
 * El correo (y la contrasena) sirven en mas de un restaurante y hace falta
 * decir en cual entrar.
 *
 * Aparte de AuthError porque la respuesta no es la misma: aquella es un 401
 * y "vuelve a intentar", esta es un 409 y "falta un dato". La pantalla de
 * ingreso se apoya en esa diferencia para mostrar el campo de la empresa
 * solo cuando de verdad hace falta.
 */
final class AmbiguousLoginError extends \RuntimeException
{
}
