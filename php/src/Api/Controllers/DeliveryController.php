<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Core\Money;
use App\Models\DeliveryInfo;
use App\Models\DeliveryZone;
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

    private static function infoOut(DeliveryInfo $i): array
    {
        return [
            'order_id' => $i->orderId,
            'address' => $i->address,
            'zone_id' => $i->zoneId,
            'zone_name' => $i->zoneName,
            'courier_id' => $i->courierId,
            'courier_name' => $i->courierName,
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
}
