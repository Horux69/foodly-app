<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Conversion de columnas de Postgres via PDO_PGSQL.
 *
 * PDO_PGSQL no mapea tipos: BOOLEAN llega como la cadena "t"/"f" (no como
 * true/false de PHP) incluso con ATTR_EMULATE_PREPARES en false, y JSONB
 * llega como el texto crudo del JSON. Sin este helper, (bool) "f" da true en
 * PHP porque es una cadena no vacia — un bug clasico y silencioso.
 */
final class Row
{
    public static function bool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    /**
     * PHP bool -> parametro bindeable a una columna BOOLEAN de Postgres.
     *
     * Con ATTR_EMULATE_PREPARES en false, PDO_PGSQL no sabe de antemano el
     * tipo de columna: manda `true` como "1" (que Postgres acepta) pero
     * `false` como cadena vacia, que el parser de boolean rechaza
     * (SQLSTATE 22P02). Bindear siempre 't'/'f' evita el caso roto.
     */
    public static function pgBool(bool $value): string
    {
        return $value ? 't' : 'f';
    }

    public static function nullableBool(mixed $value): ?bool
    {
        return $value === null ? null : self::bool($value);
    }

    /** @return array<mixed> */
    public static function json(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        return json_decode((string) $value, true) ?? [];
    }
}
