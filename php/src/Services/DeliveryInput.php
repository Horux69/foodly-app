<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Datos de entrega que llegan junto con el pedido.
 *
 * Si viene `zoneId`, la tarifa la pone la zona y se ignora cualquier
 * `delivery_fee` del cuerpo: el precio del reparto lo fija el restaurante,
 * no quien pide.
 *
 * lat y lng viajan como string decimal, igual que el resto de los NUMERIC:
 * un float perderia precision justo en los digitos que ubican el punto.
 */
final class DeliveryInput
{
    public function __construct(
        public readonly string $address,
        public readonly ?string $zoneId = null,
        public readonly ?string $lat = null,
        public readonly ?string $lng = null,
    ) {
    }
}
