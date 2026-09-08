<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Money;

/**
 * Reglas de domicilio (modulo 6).
 *
 * Puras y testeables: reciben la zona ya resuelta y deciden si el pedido se
 * puede despachar. Equivalente PHP de app/domain/delivery.py.
 */
final class DeliveryRules
{
    private function __construct()
    {
    }

    /**
     * El minimo se mide contra el subtotal, no contra el total.
     *
     * Contar el domicilio para alcanzar el minimo seria hacer trampa: la zona
     * pide un consumo minimo de comida, no de plata facturada.
     */
    public static function validateMinimum(int $subtotalCents, DeliveryZoneRules $zone): void
    {
        if ($subtotalCents >= $zone->minOrderCents) {
            return;
        }

        $faltante = $zone->minOrderCents - $subtotalCents;
        throw new DeliveryError(sprintf(
            "La zona '%s' pide un minimo de %s: faltan %s",
            $zone->name,
            Money::toDecimalString($zone->minOrderCents),
            Money::toDecimalString($faltante),
        ));
    }
}
