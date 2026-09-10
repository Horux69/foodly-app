<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Money;
use App\Domain\CourierSettlement;
use App\Domain\CourierSettlementError;
use App\Models\DeliveryInfo;
use App\Models\DeliveryZone;
use App\Repositories\BranchRepository;
use App\Repositories\CourierSettlementRepository;
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

    // ---------- Cuadre del repartidor (F9.1) ----------

    /**
     * Cuanto deberia traer cada repartidor y desde cuando se le cuenta.
     *
     * Sale de los cobros reales de sus pedidos y no de una columna que
     * alguien mantenga: asi un reembolso registrado despues le baja solo lo
     * que debe, sin que nadie tenga que acordarse.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function pendingSettlements(string $tenantId, string $branchId): array
    {
        self::ownBranch($tenantId, $branchId);
        $repo = new CourierSettlementRepository(self::pdo());

        return array_map(static function (array $fila) {
            $cobrado = Money::fromDecimalString((string) $fila['charged']);
            $devuelto = Money::fromDecimalString((string) $fila['refunded']);
            $esperado = CourierSettlement::expectedCents($cobrado, $devuelto);

            return [
                'courier_id' => $fila['courier_id'],
                'courier_name' => $fila['courier_name'],
                'from_at' => $fila['from_at'],
                'orders' => (int) $fila['orders'],
                'charged' => Money::toDecimalString($cobrado),
                'refunded' => Money::toDecimalString($devuelto),
                'expected_cash' => Money::toDecimalString($esperado),
            ];
        }, $repo->pending($tenantId, $branchId));
    }

    /**
     * El detalle de lo que trae un repartidor: sus pedidos sin cuadrar.
     *
     * @return array<string, mixed>
     */
    public static function courierDetail(string $tenantId, string $branchId, string $courierId): array
    {
        self::ownBranch($tenantId, $branchId);
        $repo = new CourierSettlementRepository(self::pdo());
        $desde = $repo->lastClosedAt($tenantId, $courierId);

        $pedidos = array_map(static fn (array $f) => [
            'order_number' => $f['order_number'],
            'total' => Money::toDecimalString(Money::fromDecimalString((string) $f['total'])),
            'cash' => Money::toDecimalString(Money::fromDecimalString((string) $f['cash'])),
            'delivered_at' => $f['delivered_at'],
        ], $repo->orders($tenantId, $branchId, $courierId, $desde));

        $esperado = array_sum(array_map(
            static fn (array $p) => Money::fromDecimalString($p['cash']),
            $pedidos,
        ));

        return [
            'courier_id' => $courierId,
            'from_at' => $desde,
            'orders' => $pedidos,
            'expected_cash' => Money::toDecimalString($esperado),
        ];
    }

    /**
     * Cierra el cuadre de un repartidor con lo que entrego.
     *
     * Se guarda lo contado y la ventana; el esperado y la diferencia salen
     * del calculo, como en el arqueo. No mueve el cajon: el efectivo de un
     * domicilio ya entro a la caja como el cobro del pedido, y registrarlo
     * otra vez lo contaria dos veces en el arqueo.
     *
     * @return array<string, mixed>
     */
    public static function settleCourier(
        string $tenantId,
        string $branchId,
        string $courierId,
        int $countedCents,
        ?string $note,
        ?string $userId,
    ): array {
        self::ownBranch($tenantId, $branchId);

        if ((new UserRepository(self::pdo(), new RoleRepository(self::pdo())))->get($tenantId, $courierId) === null) {
            throw new DeliveryServiceError('Ese repartidor no existe en este restaurante');
        }

        $repo = new CourierSettlementRepository(self::pdo());
        $desde = $repo->lastClosedAt($tenantId, $courierId);
        $detalle = self::courierDetail($tenantId, $branchId, $courierId);
        $esperado = Money::fromDecimalString($detalle['expected_cash']);

        try {
            CourierSettlement::ensureCounted($countedCents);
            CourierSettlement::ensureHayQueCuadrar($esperado, $countedCents);
        } catch (CourierSettlementError $e) {
            throw new DeliveryServiceError($e->getMessage());
        }

        $fila = $repo->create($tenantId, $branchId, $courierId, $desde, $countedCents, $note, $userId);
        $diferencia = CourierSettlement::differenceCents($countedCents, $esperado);

        return [
            'id' => $fila['id'],
            'courier_id' => $courierId,
            'from_at' => $fila['from_at'],
            'to_at' => $fila['to_at'],
            'orders' => count($detalle['orders']),
            'expected_cash' => Money::toDecimalString($esperado),
            'counted_cash' => Money::toDecimalString($countedCents),
            // Calculada, nunca guardada: manana puede aparecer un reembolso
            // de uno de estos pedidos.
            'difference' => Money::toDecimalString($diferencia),
            'summary' => CourierSettlement::describe($diferencia),
            'note' => $fila['note'],
        ];
    }

    /**
     * Los cuadres ya hechos, para poder mirar atras.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function settlementHistory(string $tenantId, string $branchId): array
    {
        self::ownBranch($tenantId, $branchId);
        return array_map(static fn (array $f) => [
            'id' => $f['id'],
            'courier_id' => $f['courier_id'],
            'courier_name' => $f['courier_name'],
            'from_at' => $f['from_at'],
            'to_at' => $f['to_at'],
            'counted_cash' => Money::toDecimalString(Money::fromDecimalString((string) $f['counted_cash'])),
            'note' => $f['note'],
            'created_by_name' => $f['created_by_name'],
        ], (new CourierSettlementRepository(self::pdo()))->history($tenantId, $branchId));
    }
}
