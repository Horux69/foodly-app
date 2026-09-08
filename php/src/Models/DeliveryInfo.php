<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Datos de entrega de un pedido. Uno por pedido como maximo.
 *
 * `zoneName` y `courierName` solo vienen cuando la consulta hizo el join
 * (getForOrder); son los mismos joinedload que la version Python pedia para
 * poder responder el nombre sin una segunda consulta.
 */
final class DeliveryInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly ?string $zoneId,
        public readonly ?string $courierId,
        public readonly string $address,
        public readonly ?string $estimatedTime,
        public readonly ?string $dispatchedAt,
        public readonly ?string $deliveredAt,
        public readonly ?string $zoneName = null,
        public readonly ?string $courierName = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            orderId: $row['order_id'],
            zoneId: $row['zone_id'],
            courierId: $row['courier_id'],
            address: $row['address'],
            estimatedTime: $row['estimated_time'],
            dispatchedAt: $row['dispatched_at'],
            deliveredAt: $row['delivered_at'],
            zoneName: $row['zone_name'] ?? null,
            courierName: $row['courier_name'] ?? null,
        );
    }
}
