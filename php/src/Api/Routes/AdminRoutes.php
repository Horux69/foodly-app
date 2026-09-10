<?php

declare(strict_types=1);

namespace App\Api\Routes;

use App\Api\Controllers\AdminController;
use App\Api\Controllers\OrderStatusController;
use App\Api\Controllers\FiscalController;
use App\Api\Controllers\PrintProfileController;
use App\Api\Router;

/** Mismas rutas (y sin prefijo /admin) que app/api/v1/admin.py monta en router.py. */
final class AdminRoutes
{
    public static function register(Router $router): void
    {
        // Numeracion autorizada y reintento de lo que quedo sin transmitir (F12.1).
        $router->get('/fiscal/resolutions', fn () => FiscalController::resolutions());
        $router->post('/fiscal/resolutions', fn () => FiscalController::createResolution());
        $router->post('/fiscal/retry', fn () => FiscalController::retry());

        // Como imprime esta sucursal (F8.2): ancho del papel y copias.
        $router->get('/print-profiles', fn () => PrintProfileController::index());
        $router->put('/print-profiles/{document}', fn ($p) => PrintProfileController::save($p));

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
        // El salon (F4.1): que mesa esta ocupada y desde cuando.
        $router->get('/branches/{branch_id:uuid}/tables/status', fn ($p) => AdminController::tableStatus($p));
        $router->put(
            '/branches/{branch_id:uuid}/tables/{table_id:uuid}/place',
            fn ($p) => AdminController::placeTable($p)
        );

        // Estados de pedido y transiciones (F5.2).
        $router->get('/order-statuses', fn () => OrderStatusController::getConfiguration());
        $router->post('/order-statuses', fn () => OrderStatusController::createStatus());
        $router->patch('/order-statuses/{status_id:uuid}', fn ($p) => OrderStatusController::updateStatus($p));
        $router->put('/order-statuses/{status_id:uuid}/initial', fn ($p) => OrderStatusController::setInitial($p));
        $router->delete('/order-statuses/{status_id:uuid}', fn ($p) => OrderStatusController::deleteStatus($p));
        $router->put('/order-statuses/{status_id:uuid}/transitions', fn ($p) => OrderStatusController::setTransitions($p));

        $router->get('/permissions', fn () => AdminController::listPermissions());

        $router->get('/roles', fn () => AdminController::listRoles());
        $router->post('/roles', fn () => AdminController::createRole());
        $router->put('/roles/{role_id:uuid}/permissions', fn ($p) => AdminController::setRolePermissions($p));

        $router->get('/users', fn () => AdminController::listUsers());
        $router->post('/users', fn () => AdminController::createUser());
        $router->patch('/users/{user_id:uuid}/active', fn ($p) => AdminController::setUserActive($p));
    }
}
