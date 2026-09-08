<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Money;
use App\Core\Row;
use App\Models\DeliveryInfo;
use App\Models\DeliveryZone;
use PDO;

final class DeliveryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    // ---------- Zonas ----------

    /** @return DeliveryZone[] */
    public function listZones(string $branchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM delivery_zones WHERE branch_id = :branch_id ORDER BY name');
        $stmt->execute(['branch_id' => $branchId]);
        return array_map(DeliveryZone::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Se sube hasta la sucursal para no aceptar una zona de otra empresa:
     * delivery_zones no tiene tenant_id propio, cuelga de branches.
     */
    public function getZone(string $tenantId, string $zoneId): ?DeliveryZone
    {
        $stmt = $this->pdo->prepare(
            'SELECT z.* FROM delivery_zones z
               JOIN branches b ON b.id = z.branch_id
              WHERE z.id = :zone_id AND b.tenant_id = :tenant_id'
        );
        $stmt->execute(['zone_id' => $zoneId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : DeliveryZone::fromRow($row);
    }

    public function getZoneByName(string $branchId, string $name): ?DeliveryZone
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM delivery_zones WHERE branch_id = :branch_id AND name = :name'
        );
        $stmt->execute(['branch_id' => $branchId, 'name' => $name]);
        $row = $stmt->fetch();
        return $row === false ? null : DeliveryZone::fromRow($row);
    }

    public function createZone(
        string $branchId,
        string $name,
        int $feeCents,
        int $minOrderCents,
        ?int $estMinutes,
    ): DeliveryZone {
        $stmt = $this->pdo->prepare(
            'INSERT INTO delivery_zones (branch_id, name, fee, min_order, est_minutes)
             VALUES (:branch_id, :name, :fee, :min_order, :est_minutes) RETURNING *'
        );
        $stmt->execute([
            'branch_id' => $branchId,
            'name' => $name,
            'fee' => Money::toDecimalString($feeCents),
            'min_order' => Money::toDecimalString($minOrderCents),
            'est_minutes' => $estMinutes,
        ]);
        return DeliveryZone::fromRow($stmt->fetch());
    }

    public function setZoneActive(string $zoneId, bool $isActive): DeliveryZone
    {
        $stmt = $this->pdo->prepare(
            'UPDATE delivery_zones SET is_active = :is_active WHERE id = :id RETURNING *'
        );
        $stmt->execute(['is_active' => Row::pgBool($isActive), 'id' => $zoneId]);
        return DeliveryZone::fromRow($stmt->fetch());
    }

    // ---------- Entrega de un pedido ----------

    /**
     * Trae la entrega con el nombre de la zona y del repartidor resueltos, que
     * es lo que la respuesta necesita; equivale a los joinedload de la version
     * Python.
     */
    public function getForOrder(string $orderId): ?DeliveryInfo
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, z.name AS zone_name, u.name AS courier_name
               FROM delivery_info d
               LEFT JOIN delivery_zones z ON z.id = d.zone_id
               LEFT JOIN users u ON u.id = d.courier_id
              WHERE d.order_id = :order_id'
        );
        $stmt->execute(['order_id' => $orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : DeliveryInfo::fromRow($row);
    }

    public function createInfo(
        string $orderId,
        string $address,
        ?string $zoneId,
        ?string $lat = null,
        ?string $lng = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO delivery_info (order_id, address, zone_id, lat, lng)
             VALUES (:order_id, :address, :zone_id, :lat, :lng)'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'address' => $address,
            'zone_id' => $zoneId,
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    public function setCourier(string $orderId, string $courierId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE delivery_info SET courier_id = :courier_id WHERE order_id = :order_id'
        );
        $stmt->execute(['courier_id' => $courierId, 'order_id' => $orderId]);
    }

    public function setEstimatedTime(string $orderId, int $minutes): void
    {
        // El calculo lo hace Postgres para que la hora salga del mismo reloj
        // que dispatched_at y delivered_at, y no del del servidor PHP.
        $stmt = $this->pdo->prepare(
            "UPDATE delivery_info
                SET estimated_time = now() + make_interval(mins => :minutes)
              WHERE order_id = :order_id"
        );
        $stmt->execute(['minutes' => $minutes, 'order_id' => $orderId]);
    }

    /**
     * Sella la salida o la llegada, solo si no estaban ya selladas: reabrir un
     * estado no debe reescribir la hora original.
     */
    public function stamp(string $orderId, string $column): void
    {
        if (!in_array($column, ['dispatched_at', 'delivered_at'], true)) {
            throw new \InvalidArgumentException("Columna de sello no valida: {$column}");
        }

        $stmt = $this->pdo->prepare(
            "UPDATE delivery_info SET {$column} = now()
              WHERE order_id = :order_id AND {$column} IS NULL"
        );
        $stmt->execute(['order_id' => $orderId]);
    }
}
