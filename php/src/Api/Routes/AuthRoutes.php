<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\AuthController;
use App\Api\Router;

final class AuthRoutes
{
    public static function register(Router $router): void
    {
        $router->post('/auth/login', fn () => AuthController::login());
        $router->get('/auth/me', fn () => AuthController::me());
    }
}
