<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Una respuesta que no es JSON: hoy, el CSV de los reportes.
 *
 * El router devuelve arreglos que el front controller serializa; esto es la
 * salida para lo que ya viene serializado y trae su propio Content-Type.
 */
final class RawResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $body,
        public readonly string $contentType,
        public readonly array $headers = [],
        public readonly int $status = 200,
    ) {
    }

    /** Un CSV que el navegador guarda como archivo en vez de mostrarlo. */
    public static function csv(string $body, string $filename): self
    {
        return new self($body, 'text/csv; charset=utf-8', [
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
