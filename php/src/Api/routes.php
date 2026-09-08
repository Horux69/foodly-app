<?php

declare(strict_types=1);

/**
 * Tabla de rutas. Un Routes.php por modulo, requerido y registrado aqui —
 * mismo patron que router.py agrega cada router de app/api/v1/*.py.
 *
 * @var App\Api\Router $router
 */

use App\Api\Routes\AdminRoutes;
use App\Api\Routes\AuthRoutes;

AuthRoutes::register($router);
AdminRoutes::register($router);

// Siguiente en migrarse: MenuRoutes, OrderRoutes, KitchenRoutes,
// PaymentRoutes, ReportRoutes (ver app/api/v1/router.py).
