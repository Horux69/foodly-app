<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\MenuController;
use App\Api\Controllers\ModifierController;
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

        // Modificadores (F5.1): antes solo se poblaban entrando a la base.
        $router->get('/menu/modifier-groups', fn () => ModifierController::listGroups());
        $router->post('/menu/modifier-groups', fn () => ModifierController::createGroup());
        $router->patch('/menu/modifier-groups/{group_id:uuid}', fn ($p) => ModifierController::updateGroup($p));
        $router->delete('/menu/modifier-groups/{group_id:uuid}', fn ($p) => ModifierController::deleteGroup($p));

        $router->post('/menu/modifier-groups/{group_id:uuid}/modifiers', fn ($p) => ModifierController::createModifier($p));
        $router->patch('/menu/modifiers/{modifier_id:uuid}', fn ($p) => ModifierController::updateModifier($p));
        $router->delete('/menu/modifiers/{modifier_id:uuid}', fn ($p) => ModifierController::deleteModifier($p));

        $router->put('/menu/items/{item_id:uuid}/modifier-groups', fn ($p) => ModifierController::setItemGroups($p));
    }
}
