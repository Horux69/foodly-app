<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Los comandos ESC/POS que arma un documento (F8.3).
 *
 * Pura y sin nada de red: no habla con ninguna impresora, solo arma la
 * secuencia de bytes que un agente local —todavía sin construir— le
 * mandaría a una. Esa es la frontera a propósito: qué imprimir es de este
 * repositorio, cómo llegan los bytes al papel es de otro proyecto, con su
 * propia decisión de plataforma y de protocolo (USB, serial, red).
 *
 * **El texto sale en ASCII sin tildes.** Sin conocer el modelo real de la
 * impresora no se puede saber qué página de códigos tiene activa, y elegir
 * mal imprimiría bytes basura donde iría una tilde — un "SUBTOTAL: $ 10.000"
 * legible sin acentos es mejor que una línea ilegible. Elegir la página de
 * códigos correcta es del agente de verdad, cuando exista.
 *
 * Los anchos de columna se miden en caracteres de la fuente por defecto de
 * la impresora (Font A), que es la unidad con la que ESC/POS entiende el
 * papel: 32 en un rollo de 58 mm, 48 en uno de 80 — los mismos dos anchos
 * que ya existen en `Domain\PrintProfile`, no una segunda configuración.
 */
final class EscPos
{
    /** Reinicia la impresora al estado de fábrica: sin esto, un `bold` de un ticket anterior podría quedar pegado. */
    public const INIT = "\x1B\x40";

    /** Corte parcial: dos hojas separadas, un solo papel usado. */
    public const CUT = "\x1D\x56\x01";

    public const ANCHO_58MM = 32;
    public const ANCHO_80MM = 48;

    private function __construct()
    {
    }

    /** Los caracteres por línea de esta térmica, a partir del ancho de papel ya configurado en F8.2. */
    public static function anchoDeColumnas(int $widthMm): int
    {
        return $widthMm === 58 ? self::ANCHO_58MM : self::ANCHO_80MM;
    }

    public static function align(string $lado): string
    {
        $codigo = match ($lado) {
            'center' => 1,
            'right' => 2,
            default => 0,
        };
        return "\x1B\x61" . chr($codigo);
    }

    public static function bold(bool $on): string
    {
        return "\x1B\x45" . ($on ? "\x01" : "\x00");
    }

    /** Doble alto y doble ancho: el tamaño que en el papel del navegador da `doc-titulo`/`doc-reimpresion`. */
    public static function doubleSize(bool $on): string
    {
        return "\x1D\x21" . ($on ? "\x11" : "\x00");
    }

    /**
     * A ASCII plano. `iconv` no siempre está disponible en un build mínimo de
     * PHP; sin él, cualquier cosa fuera del rango imprimible se cambia por
     * `?` en vez de mandarla tal cual — perder un caracter es mejor que
     * mandarle a la impresora una secuencia que no sabe interpretar.
     */
    public static function ascii(string $texto): string
    {
        if (function_exists('iconv')) {
            $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
            if ($transliterado !== false) {
                return $transliterado;
            }
        }
        return (string) preg_replace('/[^\x20-\x7E]/', '?', $texto);
    }

    public static function linea(string $texto = ''): string
    {
        return self::ascii($texto) . "\n";
    }

    public static function separador(int $ancho, string $caracter = '-'): string
    {
        return str_repeat($caracter, max(0, $ancho)) . "\n";
    }

    /**
     * Dos columnas en una línea, como una fila `justify-content:space-between`
     * del ticket en HTML. Si no caben las dos, la derecha baja a su propia
     * línea en vez de cortarse: un total cortado a la mitad no sirve.
     */
    public static function fila(string $izquierda, string $derecha, int $ancho): string
    {
        $izq = self::ascii($izquierda);
        $der = self::ascii($derecha);
        $espacio = $ancho - strlen($izq) - strlen($der);

        if ($espacio < 1) {
            return $izq . "\n" . str_pad($der, $ancho, ' ', STR_PAD_LEFT) . "\n";
        }

        return $izq . str_repeat(' ', $espacio) . $der . "\n";
    }

    /** El mismo rótulo que `canal()` en `web/js/views/cocina.js`: la comanda y el ticket dicen lo mismo que el navegador. */
    public static function nombreCanal(string $code): string
    {
        return match ($code) {
            'counter' => 'Mostrador',
            'table' => 'Mesa',
            'delivery' => 'Domicilio',
            'whatsapp' => 'WhatsApp',
            'app' => 'App',
            default => $code,
        };
    }

    /**
     * `d/m H:i`, en la zona horaria de la sucursal: la de un servidor no
     * dice nada de dónde está el restaurante.
     *
     * El segundo argumento del constructor de `DateTimeImmutable` se ignora
     * cuando la cadena ya trae su propio desfase (como el `Z` de un ISO en
     * UTC, que es como llega `created_at`) — por eso hace falta `setTimezone`
     * aparte, y no basta con pasarla al construir.
     */
    public static function fechaHora(string $iso, string $timezone): string
    {
        try {
            return (new \DateTimeImmutable($iso))
                ->setTimezone(new \DateTimeZone($timezone))
                ->format('d/m H:i');
        } catch (\Exception) {
            return $iso;
        }
    }
}
