<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\MenuController;
use App\Api\Router;

/** Mismas rutas que app/api/v1/menu.py, montadas bajo /menu como en router.py. */
final class MenuRoutes
{
    public static function register(Router $router): void
    {
        $router->get('/menu', fn () => MenuController::getMenu());
        $router->get('/menu/catalog', fn () => MenuController::getCatalog());

        $router->post('/menu/categories', fn () => MenuController::createCategory());

        $router->post('/menu/items', fn () => MenuController::createItem());
        $router->patch('/menu/items/{item_id:uuid}', fn ($p) => MenuController::updateItem($p));
        $router->patch('/menu/items/{item_id:uuid}/availability', fn ($p) => MenuController::updateItemAvailability($p));
        $router->put('/menu/items/{item_id:uuid}/branch-override', fn ($p) => MenuController::setBranchOverride($p));
    }
}
