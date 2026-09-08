<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\DeliveryInfo;
use App\Models\DeliveryZone;
use App\Repositories\BranchRepository;
use App\Repositories\DeliveryRepository;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;
use App\Repositories\RoleRepository;
use PDO;

/**
 * Domicilios (modulo 6): zonas de reparto y seguimiento de la entrega.
 *
 * Equivalente PHP de app/services/delivery_service.py.
 */
final class DeliveryService
{
    private static function pdo(): PDO
    {
        return Database::app();
    }

    private static function ownBranch(string $tenantId, string $branchId): void
    {
        if ((new BranchRepository(self::pdo()))->get($tenantId, $branchId) === null) {
            throw new DeliveryServiceError('La sucursal no existe para este tenant');
        }
    }

    // ---------- Zonas ----------

    /** @return DeliveryZone[] */
    public static function listZones(string $tenantId, string $branchId): array
    {
        self::ownBranch($tenantId, $branchId);
        return (new DeliveryRepository(self::pdo()))->listZones($branchId);
    }

    public static function createZone(
        string $tenantId,
        string $branchId,
        string $name,
        int $feeCents,
        int $minOrderCents,
        ?int $estMinutes,
    ): DeliveryZone {
        self::ownBranch($tenantId, $branchId);

        $repo = new DeliveryRepository(self::pdo());
        if ($repo->getZoneByName($branchId, $name) !== null) {
            throw new DeliveryServiceError("Ya existe una zona llamada '{$name}' en esta sucursal");
        }

        return $repo->createZone($branchId, $name, $feeCents, $minOrderCents, $estMinutes);
    }

    public static function setZoneActive(string $tenantId, string $zoneId, bool $isActive): DeliveryZone
    {
        $repo = new DeliveryRepository(self::pdo());
        if ($repo->getZone($tenantId, $zoneId) === null) {
            throw new DeliveryServiceError('La zona no existe para este tenant');
        }
        return $repo->setZoneActive($zoneId, $isActive);
    }

    // ---------- Entrega de un pedido ----------

    public static function getDelivery(string $tenantId, string $orderId): DeliveryInfo
    {
        if ((new OrderRepository(self::pdo()))->getById($tenantId, $orderId) === null) {
            throw new DeliveryServiceError('Pedido no encontrado');
        }

        $info = (new DeliveryRepository(self::pdo()))->getForOrder($orderId);
        if ($info === null) {
            throw new DeliveryServiceError('Este pedido no es un domicilio');
        }
        return $info;
    }

    /**
     * Asigna un repartidor. No marca despachado: eso lo hace el avance de
     * estado, para que la hora de salida sea una sola y venga del mismo lugar.
     */
    public static function assignCourier(string $tenantId, string $orderId, string $courierId): DeliveryInfo
    {
        self::getDelivery($tenantId, $orderId);

        $pdo = self::pdo();
        $courier = (new UserRepository($pdo, new RoleRepository($pdo)))->get($tenantId, $courierId);
        if ($courier === null) {
            throw new DeliveryServiceError('El repartidor no existe para este tenant');
        }
        if (!$courier->isActive) {
            throw new DeliveryServiceError("{$courier->name} esta inactivo");
        }

        (new DeliveryRepository($pdo))->setCourier($orderId, $courierId);
        return self::getDelivery($tenantId, $orderId);
    }

    public static function setEstimatedTime(string $tenantId, string $orderId, int $minutes): DeliveryInfo
    {
        self::getDelivery($tenantId, $orderId);
        (new DeliveryRepository(self::pdo()))->setEstimatedTime($orderId, $minutes);
        return self::getDelivery($tenantId, $orderId);
    }

    /**
     * Anota cuando salio y cuando llego un domicilio.
     *
     * Se guia por la categoria del estado y no por su codigo: un restaurante
     * puede llamar 'En moto' a lo que otro llama 'En camino', y ambos son
     * 'in_transit'. Los pedidos sin domicilio no tienen nada que anotar.
     */
    public static function stampByCategory(string $orderId, string $category): void
    {
        $column = match ($category) {
            'in_transit' => 'dispatched_at',
            'completed' => 'delivered_at',
            default => null,
        };
        if ($column === null) {
            return;
        }

        (new DeliveryRepository(self::pdo()))->stamp($orderId, $column);
    }
}
