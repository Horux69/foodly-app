<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Genera un CSV que Excel abre bien de doble clic.
 *
 * Dos decisiones que parecen detalles y no lo son:
 *
 * - **Punto y coma, no coma.** Excel usa como separador el que diga la
 *   configuracion regional, y en espanol es el punto y coma. Con comas, todo
 *   el archivo aterriza en una sola columna, que es como llega la mitad de
 *   los CSV del mundo.
 * - **Con BOM.** Sin el, Excel lee el archivo como latin-1 y "Hamburguesa
 *   clásica" sale "clÃ¡sica".
 *
 * Es una eleccion para quien va a abrir esto —el dueno de un restaurante, en
 * Excel— y no la mas portable: `pandas.read_csv` necesita `sep=';'`.
 */
final class Csv
{
    public const SEPARADOR = ';';
    public const BOM = "\u{FEFF}";

    /**
     * @param string[] $encabezados
     * @param array<int, array<int, string|int|float|null>> $filas
     */
    public static function render(array $encabezados, array $filas): string
    {
        $lineas = [self::linea($encabezados)];
        foreach ($filas as $fila) {
            $lineas[] = self::linea($fila);
        }
        // CRLF: es lo que espera Excel y lo que dice el RFC 4180.
        return self::BOM . implode("\r\n", $lineas) . "\r\n";
    }

    /** @param array<int, string|int|float|null> $campos */
    private static function linea(array $campos): string
    {
        return implode(self::SEPARADOR, array_map(self::campo(...), $campos));
    }

    private static function campo(string|int|float|null $valor): string
    {
        $texto = (string) ($valor ?? '');

        // Se entrecomilla si lleva el separador, comillas o saltos de linea;
        // las comillas de dentro se duplican (RFC 4180).
        if (preg_match('/[";\r\n]/', $texto) === 1) {
            return '"' . str_replace('"', '""', $texto) . '"';
        }
        return $texto;
    }
}
