<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\CustomerController;
use App\Api\Router;

/**
 * Mismas rutas que app/api/v1/customers.py, que router.py montaba con
 * prefix="/customers" — aqui el prefijo va escrito en cada patron.
 */
final class CustomerRoutes
{
    public static function register(Router $router): void
    {
        $router->get('/customers', fn () => CustomerController::search());
        $router->get('/customers/{customer_id:uuid}', fn ($p) => CustomerController::detail($p));
        $router->patch('/customers/{customer_id:uuid}', fn ($p) => CustomerController::update($p));
    }
}
