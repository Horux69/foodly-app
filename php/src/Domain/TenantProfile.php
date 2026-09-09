<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Los datos de identidad del restaurante: nombre, modelo de negocio y moneda.
 *
 * Van aparte de TenantSettings porque son columnas de `tenants` y no claves
 * del JSONB, pero sobre todo porque tienen una regla propia: la moneda no se
 * cambia sin querer.
 */
final class TenantProfile
{
    public const NOMBRE_MAX = 150;

    /** ISO 4217 alfabetico: tres letras. La columna es CHAR(3). */
    private const CURRENCY = '/^[A-Z]{3}$/';

    public static function normalizeCurrency(string $raw): string
    {
        return strtoupper(trim($raw));
    }

    public static function validate(string $name, string $businessType, string $currency): void
    {
        if (trim($name) === '') {
            throw new TenantProfileError('El nombre del restaurante no puede quedar vacio');
        }
        if (mb_strlen($name) > self::NOMBRE_MAX) {
            throw new TenantProfileError('El nombre del restaurante no puede pasar de ' . self::NOMBRE_MAX . ' caracteres');
        }
        if (!in_array($businessType, TenantSettings::BUSINESS_TYPES, true)) {
            throw new TenantProfileError(
                'Modelo de negocio desconocido: ' . $businessType . '. Validos: ' . implode(', ', TenantSettings::BUSINESS_TYPES)
            );
        }
        if (preg_match(self::CURRENCY, $currency) !== 1) {
            throw new TenantProfileError("Moneda invalida: '{$currency}'. Se esperan tres letras, como COP o USD");
        }
    }

    /**
     * Cambiar la moneda no reconvierte nada.
     *
     * Los pedidos ya emitidos guardan sus cifras en `NUMERIC(10,2)` sin
     * moneda: cambiar el codigo de COP a USD no divide por cuatro mil, solo
     * hace que los reportes historicos se lean con el simbolo equivocado. Es
     * un cambio que casi siempre es un dedazo, asi que exige decirlo aparte y
     * no solo mandar el campo. La confirmacion se pide aqui y no en el
     * navegador porque una pantalla no es el unico cliente de la API.
     */
    public static function ensureCurrencyChangeConfirmed(string $current, string $next, bool $confirmed): void
    {
        if ($next === $current || $confirmed) {
            return;
        }
        throw new TenantProfileError(
            "Cambiar la moneda de {$current} a {$next} no reconvierte los pedidos ya emitidos: "
            . 'sus cifras se quedan como estan y pasarian a leerse en la moneda nueva. '
            . 'Confirma el cambio para aplicarlo.'
        );
    }
}
