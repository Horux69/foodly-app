<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Core\Money;
use App\Domain\DeliveryPromise;
use App\Models\DeliveryInfo;
use App\Models\DeliveryZone;
use App\Services\AdminService;
use App\Services\DeliveryService;
use App\Services\DeliveryServiceError;

/** Equivalente PHP de app/api/v1/delivery.py. */
final class DeliveryController
{
    private static function zoneOut(DeliveryZone $z): array
    {
        return [
            'id' => $z->id,
            'branch_id' => $z->branchId,
            'name' => $z->name,
            'fee' => Money::toDecimalString($z->feeCents),
            'min_order' => Money::toDecimalString($z->minOrderCents),
            'est_minutes' => $z->estMinutes,
            'is_active' => $z->isActive,
        ];
    }

    /** Publico porque el detalle del pedido embebe la entrega con esta misma forma. */
    public static function infoOut(DeliveryInfo $i): array
    {
        return [
            'order_id' => $i->orderId,
            'address' => $i->address,
            'zone_id' => $i->zoneId,
            'zone_name' => $i->zoneName,
            'courier_id' => $i->courierId,
            'courier_name' => $i->courierName,
            // Como va la promesa (F9.5). Lo decide el dominio y no la
            // pantalla: el tablero, el reporte y el agente de WhatsApp
            // tienen que llamar tarde a lo mismo.
            'promise_state' => DeliveryPromise::estado($i->estimatedTime, $i->deliveredAt),
            'late_minutes' => DeliveryPromise::retrasoMinutos($i->estimatedTime, $i->deliveredAt),
            'estimated_time' => $i->estimatedTime,
            'dispatched_at' => $i->dispatchedAt,
            'delivered_at' => $i->deliveredAt,
        ];
    }

    // ---------- Zonas ----------

    public static function listZones(array $params): array
    {
        // Tambien con orders.create: quien toma un domicilio tiene que poder
        // elegir la zona, y no deberia hacer falta darle a la caja un
        // permiso de administracion para eso.
        $ctx = Deps::requireAny(Deps::getContext(), 'settings.view', 'orders.create');
        try {
            $zones = DeliveryService::listZones($ctx->tenantId, $params['branch_id']);
        } catch (DeliveryServiceError $e) {
            throw new ApiException(404, $e->getMessage());
        }
        return array_map(self::zoneOut(...), $zones);
    }

    public static function createZone(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $body = Request::json();

        $fee = Money::fromDecimalString(Request::decimalString($body, 'fee'));
        $minOrder = Money::fromDecimalString(Request::decimalString($body, 'min_order', '0'));
        if ($fee < 0 || $minOrder < 0) {
            throw new ApiException(422, "'fee' y 'min_order' no pueden ser negativos");
        }

        // est_minutes es opcional, pero si viene tiene que ser un tiempo real:
        // una zona que promete cero minutos no es un dato, es un error.
        $estMinutes = array_key_exists('est_minutes', $body) && $body['est_minutes'] !== null
            ? Request::int($body, 'est_minutes', min: 1)
            : null;

        try {
            $zone = DeliveryService::createZone(
                $ctx->tenantId,
                $params['branch_id'],
                Request::string($body, 'name', 1, 100),
                $fee,
                $minOrder,
                $estMinutes,
            );
        } catch (DeliveryServiceError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::zoneOut($zone), 201);
    }

    public static function setZoneActive(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $body = Request::json();
        try {
            $zone = DeliveryService::setZoneActive(
                $ctx->tenantId,
                $params['zone_id'],
                Request::bool($body, 'is_active'),
            );
        } catch (DeliveryServiceError $e) {
            throw new ApiException(404, $e->getMessage());
        }
        return self::zoneOut($zone);
    }

    /**
     * Los repartidores entre los que se puede elegir.
     *
     * Bajo 'delivery.assign' y no bajo 'users.manage': quien despacha
     * domicilios necesita esta lista y no deberia hacer falta darle un
     * permiso de administracion para que pueda trabajar. Devuelve solo id y
     * nombre —lo unico que el selector necesita—, no la ficha del empleado.
     */
    public static function listCouriers(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'delivery.assign');
        return array_map(
            static fn ($u) => ['id' => $u->id, 'name' => $u->name],
            AdminService::listCouriers($ctx->tenantId),
        );
    }

    // ---------- Entrega de un pedido ----------

    public static function getDelivery(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');
        try {
            return self::infoOut(DeliveryService::getDelivery($ctx->tenantId, $params['order_id']));
        } catch (DeliveryServiceError $e) {
            throw new ApiException(404, $e->getMessage());
        }
    }

    public static function assignCourier(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'delivery.assign');
        $body = Request::json();
        try {
            $info = DeliveryService::assignCourier(
                $ctx->tenantId,
                $params['order_id'],
                Request::uuid($body, 'courier_id'),
            );
        } catch (DeliveryServiceError $e) {
            throw new ApiException(422, $e->getMessage());
        }
        return self::infoOut($info);
    }

    public static function setEta(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'delivery.assign');
        $body = Request::json();
        $minutes = Request::int($body, 'minutes', min: 1);
        if ($minutes > 600) {
            throw new ApiException(422, "'minutes' debe estar entre 1 y 600");
        }

        try {
            $info = DeliveryService::setEstimatedTime($ctx->tenantId, $params['order_id'], $minutes);
        } catch (DeliveryServiceError $e) {
            throw new ApiException(404, $e->getMessage());
        }
        return self::infoOut($info);
    }

    // ---------- Cuadre del repartidor (F9.1) ----------

    /**
     * Cuanto debe traer cada repartidor.
     *
     * Bajo 'cash.close' y no bajo 'delivery.assign': es el mismo control del
     * arqueo sobre otra caja —la del repartidor—, y ver cuanto deberia haber
     * antes de contarlo es justo lo que ese permiso separa.
     */
    public static function pendingSettlements(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'cash.close');
        try {
            return [
                'couriers' => DeliveryService::pendingSettlements($ctx->tenantId, Deps::activeBranchId($ctx)),
                'history' => DeliveryService::settlementHistory($ctx->tenantId, Deps::activeBranchId($ctx)),
            ];
        } catch (DeliveryServiceError $e) {
            throw new ApiException(404, $e->getMessage());
        }
    }

    /** Los pedidos que entran en el cuadre de un repartidor. */
    public static function courierSettlement(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'cash.close');
        try {
            return DeliveryService::courierDetail($ctx->tenantId, Deps::activeBranchId($ctx), $params['courier_id']);
        } catch (DeliveryServiceError $e) {
            throw new ApiException(404, $e->getMessage());
        }
    }

    /** Cierra el cuadre con lo que el repartidor entrego. */
    public static function settleCourier(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'cash.close');
        $body = Request::json();

        try {
            $cuadre = DeliveryService::settleCourier(
                $ctx->tenantId,
                Deps::activeBranchId($ctx),
                $params['courier_id'],
                Money::fromDecimalString(Request::decimalString($body, 'counted_cash')),
                Request::optionalString($body, 'note'),
                $ctx->userId,
            );
        } catch (DeliveryServiceError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse($cuadre, 201);
    }
}
