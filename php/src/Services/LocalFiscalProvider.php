<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Sin proveedor tecnologico conectado.
 *
 * El documento se numera con la resolucion del restaurante y se imprime,
 * pero no se transmite a nadie: queda en contingencia. Es lo que necesita un
 * restaurante que todavia no esta obligado, o uno que esta migrando y aun no
 * tiene su proveedor.
 *
 * No es un simulador que finge exito. Decir 'accepted' sin haber transmitido
 * nada seria mentir en el unico sitio donde la mentira la descubre la
 * autoridad y no el usuario.
 */
final class LocalFiscalProvider implements FiscalProvider
{
    public function transmit(array $documento): FiscalResult
    {
        return new FiscalResult(
            FiscalResult::CONTINGENCY,
            null,
            ['nota' => 'Sin proveedor tecnologico configurado: el documento queda numerado y sin transmitir'],
        );
    }

    public function code(): string
    {
        return 'local';
    }
}
