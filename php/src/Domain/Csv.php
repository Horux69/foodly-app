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
 *
 * Y una tercera que no es de formato sino de seguridad: **una celda no puede
 * empezar por `=`, `+`, `-` o `@`** sin desactivarla antes. Excel y
 * LibreOffice tratan eso como una formula y la evaluan al abrir el archivo,
 * asi que un motivo de anulacion escrito como `=HYPERLINK(...)` —texto libre
 * que cualquiera con permiso para anular puede teclear— se ejecutaria en la
 * maquina del dueno. Se le antepone un apostrofo, que es como se marca texto
 * en una hoja de calculo. Los numeros no lo llevan: un descuento de -1500
 * tiene que seguir sumandose.
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
        $texto = self::desactivarFormula((string) ($valor ?? ''));

        // Se entrecomilla si lleva el separador, comillas o saltos de linea;
        // las comillas de dentro se duplican (RFC 4180).
        if (preg_match('/[";\r\n]/', $texto) === 1) {
            return '"' . str_replace('"', '""', $texto) . '"';
        }
        return $texto;
    }

    /**
     * Deja de ser formula lo que empieza como una.
     *
     * Un numero se deja intacto aunque empiece por signo: es el caso de todos
     * los importes negativos del reporte de ajustes, y anteponerles nada los
     * volveria texto en la hoja, que es justo lo contrario de lo que se
     * exporta un CSV para hacer.
     */
    private static function desactivarFormula(string $texto): string
    {
        if ($texto === '' || !str_contains("=+-@\t\r", $texto[0])) {
            return $texto;
        }
        if (is_numeric($texto)) {
            return $texto;
        }
        return "'" . $texto;
    }
}
