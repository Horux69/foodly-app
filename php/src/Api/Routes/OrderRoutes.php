<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\KitchenController;
use App\Api\Controllers\OrderController;
use App\Api\Controllers\PaymentController;
use App\Api\Router;

/**
 * Pedidos, caja y cocina — mismas rutas que montan orders.py, payments.py y
 * kitchen.py en router.py (los pagos cuelgan de /orders, no de un prefijo propio).
 */
final class OrderRoutes
{
    public static function register(Router $router): void
    {
        $router->post('/orders', fn () => OrderController::create());
        $router->post('/orders/preview', fn () => OrderController::preview());
        $router->get('/orders', fn () => OrderController::list());
        $router->get('/orders/{order_id:uuid}', fn ($p) => OrderController::get($p));
        $router->get('/orders/{order_id:uuid}/next-statuses', fn ($p) => OrderController::nextStatuses($p));
        $router->post('/orders/{order_id:uuid}/status', fn ($p) => OrderController::changeStatus($p));

        $router->post('/orders/{order_id:uuid}/payments', fn ($p) => PaymentController::register($p));
        $router->get('/orders/{order_id:uuid}/payments', fn ($p) => PaymentController::list($p));
        $router->get('/orders/{order_id:uuid}/balance', fn ($p) => PaymentController::balance($p));

        $router->get('/kitchen/orders', fn () => KitchenController::board());
    }
}
