<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Un identificador corto y legible a partir de un nombre.
 *
 * Se usa para el slug de la empresa, que es lo que alguien escribe al entrar
 * cuando su correo esta en mas de un restaurante. Tiene que sobrevivir a que
 * lo dicten por telefono: sin acentos, sin espacios y sin mayusculas.
 */
final class Slug
{
    public const MAX = 50;

    private const ACENTOS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
    ];

    public static function from(string $texto): string
    {
        $base = strtr(mb_strtolower(trim($texto)), self::ACENTOS);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');

        return $base === '' ? 'empresa' : substr($base, 0, self::MAX);
    }

    /**
     * El mismo slug, pero garantizando que no choque con los que ya existen.
     *
     * @param callable(string): bool $existe
     */
    public static function unique(string $texto, callable $existe): string
    {
        $base = self::from($texto);
        $candidato = $base;

        for ($i = 2; $existe($candidato); $i++) {
            $sufijo = '-' . $i;
            $candidato = substr($base, 0, self::MAX - strlen($sufijo)) . $sufijo;
        }
        return $candidato;
    }
}
