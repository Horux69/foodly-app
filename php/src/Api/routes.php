<?php

declare(strict_types=1);

/**
 * Tabla de rutas. Se llena a medida que se migra cada modulo — hoy solo
 * trae /health (resuelto directo en public/index.php). Convencion: un
 * archivo Routes.php por modulo (auth, admin, menu, orders, ...) que este
 * archivo va a requerir, igual que router.py agrega cada router de
 * app/api/v1/*.py.
 *
 * @var App\Api\Router $router
 */
