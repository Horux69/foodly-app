<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\DeliveryController;
use App\Api\Router;

/** Mismas rutas que app/api/v1/delivery.py monta en router.py. */
final class DeliveryRoutes
{
    public static function register(Router $router): void
    {
        $router->get(
            '/branches/{branch_id:uuid}/delivery-zones',
            fn ($p) => DeliveryController::listZones($p),
        );
        $router->post(
            '/branches/{branch_id:uuid}/delivery-zones',
            fn ($p) => DeliveryController::createZone($p),
        );
        $router->patch(
            '/delivery-zones/{zone_id:uuid}/active',
            fn ($p) => DeliveryController::setZoneActive($p),
        );

        $router->get('/couriers', fn () => DeliveryController::listCouriers());

        // Cuadre del repartidor (F9.1): el arqueo sobre la otra caja.
        $router->get('/couriers/settlements', fn () => DeliveryController::pendingSettlements());
        $router->get(
            '/couriers/{courier_id:uuid}/settlement',
            fn ($p) => DeliveryController::courierSettlement($p),
        );
        $router->post(
            '/couriers/{courier_id:uuid}/settlement',
            fn ($p) => DeliveryController::settleCourier($p),
        );

        $router->get('/orders/{order_id:uuid}/delivery', fn ($p) => DeliveryController::getDelivery($p));
        $router->put(
            '/orders/{order_id:uuid}/delivery/courier',
            fn ($p) => DeliveryController::assignCourier($p),
        );
        $router->put('/orders/{order_id:uuid}/delivery/eta', fn ($p) => DeliveryController::setEta($p));
    }
}
