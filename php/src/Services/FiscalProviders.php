<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Que proveedor usa cada empresa.
 *
 * Hoy solo esta el local —numerar e imprimir sin transmitir—. Cuando entre
 * uno real se agrega aqui y se elige por configuracion del tenant, sin tocar
 * el servicio ni los controladores; igual que PaymentProviders.
 */
final class FiscalProviders
{
    public const LOCAL = 'local';

    /** @return array<string, FiscalProvider> */
    private static function all(): array
    {
        return [self::LOCAL => new LocalFiscalProvider()];
    }

    public static function get(string $code): FiscalProvider
    {
        return self::all()[$code] ?? new LocalFiscalProvider();
    }

    /** @return string[] */
    public static function available(): array
    {
        return array_keys(self::all());
    }
}
