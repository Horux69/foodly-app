<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\KitchenController;
use App\Api\Controllers\FiscalController;
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
        $router->get('/orders/{order_id:uuid}/history', fn ($p) => OrderController::history($p));
        // Modificar un pedido abierto (F4.0): el permiso orders.edit existia
        // en el catalogo desde el principio y no lo comprobaba nadie.
        $router->post('/orders/{order_id:uuid}/items', fn ($p) => OrderController::addItems($p));
        $router->patch('/orders/{order_id:uuid}/items/{item_id:uuid}', fn ($p) => OrderController::setItemQuantity($p));
        $router->delete('/orders/{order_id:uuid}/items/{item_id:uuid}', fn ($p) => OrderController::removeItem($p));

        // Descuento con motivo y tope (F7.2): el permiso orders.discount
        // tampoco lo comprobaba nadie.
        $router->get('/discount-reasons', fn () => OrderController::discountReasons());
        $router->put('/orders/{order_id:uuid}/discount', fn ($p) => OrderController::setDiscount($p));
        // La propina se decide al cobrar, no al pedir (F7.3).
        $router->put('/orders/{order_id:uuid}/tip', fn ($p) => OrderController::setTip($p));

        // Mover de mesa y unir cuentas (F4.3).
        $router->put('/orders/{order_id:uuid}/table', fn ($p) => OrderController::moveToTable($p));
        $router->post('/orders/{order_id:uuid}/merge', fn ($p) => OrderController::merge($p));

        // Documento fiscal de la venta (F12.1).
        $router->get('/orders/{order_id:uuid}/fiscal-document', fn ($p) => FiscalController::show($p));
        $router->post('/orders/{order_id:uuid}/fiscal-document', fn ($p) => FiscalController::emit($p));

        $router->post('/orders/{order_id:uuid}/status', fn ($p) => OrderController::changeStatus($p));

        $router->post('/orders/{order_id:uuid}/payments', fn ($p) => PaymentController::register($p));
        $router->get('/orders/{order_id:uuid}/payments', fn ($p) => PaymentController::list($p));
        $router->get('/orders/{order_id:uuid}/balance', fn ($p) => PaymentController::balance($p));
        $router->get('/orders/{order_id:uuid}/split', fn ($p) => PaymentController::split($p));
        $router->post(
            '/orders/{order_id:uuid}/payments/{payment_id:uuid}/refund',
            fn ($p) => PaymentController::refund($p),
        );

        $router->get('/kitchen/orders', fn () => KitchenController::board());
    }
}
