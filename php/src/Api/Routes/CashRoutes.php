<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\CashController;
use App\Api\Router;

/** Turnos de caja y arqueo (fase 2). */
final class CashRoutes
{
    public static function register(Router $router): void
    {
        $router->get('/cash/session', fn () => CashController::current());
        $router->post('/cash/session', fn () => CashController::open());
        $router->post('/cash/sessions/{session_id:uuid}/close', fn ($p) => CashController::close($p));
        $router->get('/cash/sessions', fn () => CashController::history());

        // Entradas y salidas del cajon (F7.1): el arqueo solo conocia ventas.
        $router->get('/cash/movements', fn () => CashController::movements());
        $router->post('/cash/movements', fn () => CashController::addMovement());
    }
}
