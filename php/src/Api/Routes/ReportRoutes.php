<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\ReportController;
use App\Api\Router;

/** Mismas rutas que app/api/v1/reports.py, montadas bajo /reports como en router.py. */
final class ReportRoutes
{
    public static function register(Router $router): void
    {
        $router->get('/reports/sales', fn () => ReportController::sales());
        $router->get('/reports/top-products', fn () => ReportController::topProducts());
        $router->get('/reports/prep-times', fn () => ReportController::prepTimes());
        $router->get('/reports/peak-hours', fn () => ReportController::peakHours());

        // Los de cierre: siguen la plata, no el pedido.
        $router->get('/reports/payment-methods', fn () => ReportController::paymentMethods());
        $router->get('/reports/sales-by-user', fn () => ReportController::salesByUser());
        // Ventas por canal y su comision (F9.4).
        $router->get('/reports/sales-by-source', fn () => ReportController::salesBySource());
        // Cumplimiento de la promesa de entrega (F9.5).
        $router->get('/reports/delivery-promise', fn () => ReportController::deliveryPromise());
        // Por quien atendio la mesa, no por quien digito (F4.4).
        $router->get('/reports/sales-by-server', fn () => ReportController::salesByServer());
        $router->get('/reports/adjustments', fn () => ReportController::adjustments());

        // Cualquiera de ellos como CSV: ?report=sales|top-products|...
        $router->get('/reports/export', fn () => ReportController::export());
    }
}
