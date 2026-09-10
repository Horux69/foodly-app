<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Quien transmite un documento a la autoridad.
 *
 * Es el mismo patron que PaymentProvider y por la misma razon: el dia que
 * entre un proveedor tecnologico de verdad —o el de otro pais— no tiene que
 * tocarse nada mas. Lo que el sistema hace sin proveedor —numerar con la
 * resolucion, imprimir, guardar— ya funciona.
 */
interface FiscalProvider
{
    /**
     * @param array<string, mixed> $documento lo que se va a transmitir
     */
    public function transmit(array $documento): FiscalResult;

    /** Como se llama, para dejarlo escrito en el documento emitido. */
    public function code(): string;
}
