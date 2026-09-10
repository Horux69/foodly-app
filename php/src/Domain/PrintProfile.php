<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Como sale impreso un documento en una sucursal.
 *
 * El ancho estaba fijo en el CSS y las copias eran siempre una. Ninguna de
 * las dos cosas es igual en todos los restaurantes: hay termicas de 58 y de
 * 80 mm, y el ticket se archiva por duplicado en muchos sitios.
 *
 * Los valores por defecto son los de antes, asi que un restaurante que no
 * configure nada imprime exactamente igual que hasta ahora.
 */
final class PrintProfile
{
    public const COMANDA = 'comanda';
    public const TICKET = 'ticket';
    public const PRECUENTA = 'precuenta';
    public const DOCUMENTOS = [self::COMANDA, self::TICKET, self::PRECUENTA];

    /** Los dos formatos que existen en el mercado. */
    public const ANCHOS = [58, 80];

    public const ANCHO_DEFECTO = 80;
    public const COPIAS_MAX = 3;

    public function __construct(
        public readonly string $document,
        public readonly int $widthMm = self::ANCHO_DEFECTO,
        public readonly int $copies = 1,
    ) {
    }

    /**
     * Lo que mide el papel por dentro, ya descontados los margenes.
     *
     * Un rollo de 80 imprime 72 y uno de 58 imprime 48: son los anchos
     * utiles de las termicas, no una proporcion.
     */
    public function contentWidthMm(): int
    {
        return $this->widthMm === 58 ? 48 : 72;
    }

    /** @throws PrintProfileError */
    public static function validate(string $document, int $widthMm, int $copies): void
    {
        if (!in_array($document, self::DOCUMENTOS, true)) {
            throw new PrintProfileError(
                "Documento desconocido: '{$document}'. Validos: " . implode(', ', self::DOCUMENTOS)
            );
        }
        if (!in_array($widthMm, self::ANCHOS, true)) {
            throw new PrintProfileError('El ancho solo puede ser 58 u 80 mm');
        }
        if ($copies < 1 || $copies > self::COPIAS_MAX) {
            throw new PrintProfileError('Las copias van de 1 a ' . self::COPIAS_MAX);
        }
    }

    /**
     * Los perfiles de una sucursal, completando con los valores por defecto
     * los documentos que nadie configuro.
     *
     * @param array<string, array{width_mm: int, copies: int}> $guardados
     * @return array<int, self>
     */
    public static function completar(array $guardados): array
    {
        return array_map(
            static fn (string $documento) => new self(
                $documento,
                $guardados[$documento]['width_mm'] ?? self::ANCHO_DEFECTO,
                $guardados[$documento]['copies'] ?? 1,
            ),
            self::DOCUMENTOS,
        );
    }
}
