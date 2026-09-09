<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\AdminController;
use App\Api\Router;

/** Mismas rutas (y sin prefijo /admin) que app/api/v1/admin.py monta en router.py. */
final class AdminRoutes
{
    public static function register(Router $router): void
    {
        $router->get('/settings', fn () => AdminController::getSettings());
        $router->patch('/settings', fn () => AdminController::updateSettings());

        $router->get('/branches', fn () => AdminController::listBranches());
        $router->post('/branches', fn () => AdminController::createBranch());
        $router->patch('/branches/{branch_id:uuid}/active', fn ($p) => AdminController::setBranchActive($p));

        // Horarios de sucursal (F5.3): franjas por dia y por canal.
        $router->get('/branches/{branch_id:uuid}/schedules', fn ($p) => AdminController::listSchedules($p));
        $router->post('/branches/{branch_id:uuid}/schedules', fn ($p) => AdminController::createSchedule($p));
        $router->patch('/schedules/{schedule_id:uuid}/active', fn ($p) => AdminController::setScheduleActive($p));
        $router->delete('/schedules/{schedule_id:uuid}', fn ($p) => AdminController::deleteSchedule($p));

        $router->get('/tax-rates', fn () => AdminController::listTaxRates());
        $router->post('/tax-rates', fn () => AdminController::createTaxRate());
        $router->put('/tax-rates/{tax_rate_id:uuid}/default', fn ($p) => AdminController::setDefaultTaxRate($p));

        $router->get('/branches/{branch_id:uuid}/tables', fn ($p) => AdminController::listTables($p));
        $router->post('/branches/{branch_id:uuid}/tables', fn ($p) => AdminController::createTable($p));

        $router->get('/permissions', fn () => AdminController::listPermissions());

        $router->get('/roles', fn () => AdminController::listRoles());
        $router->post('/roles', fn () => AdminController::createRole());
        $router->put('/roles/{role_id:uuid}/permissions', fn ($p) => AdminController::setRolePermissions($p));

        $router->get('/users', fn () => AdminController::listUsers());
        $router->post('/users', fn () => AdminController::createUser());
        $router->patch('/users/{user_id:uuid}/active', fn ($p) => AdminController::setUserActive($p));
    }
}
