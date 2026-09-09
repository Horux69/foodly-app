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
        $router->get('/reports/adjustments', fn () => ReportController::adjustments());
    }
}
