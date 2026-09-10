<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Models\Branch;
use App\Models\BranchScheduleRow;
use App\Models\Role;
use App\Models\Table;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\AdminError;
use App\Services\AdminService;
use App\Services\FloorError;
use App\Services\FloorService;
use App\Core\Permissions;
use App\Domain\ScheduleRules;

/**
 * Equivalente PHP de app/api/v1/admin.py. Cada metodo repite el mismo
 * patron: resolver el contexto (que ya exige el permiso indicado), leer y
 * validar el body, llamar al servicio, y mapear el resultado a las mismas
 * claves snake_case que ya devuelve la API en Python — el frontend en web/
 * no cambia una linea.
 */
final class AdminController
{
    private static function branchOut(Branch $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'code' => $b->code,
            'timezone' => $b->timezone,
            'address' => $b->address,
            'phone' => $b->phone,
            'is_active' => $b->isActive,
        ];
    }

    private static function taxRateOut(TaxRate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'rate' => $t->rate,
            'included_in_price' => $t->includedInPrice,
            'is_default' => $t->isDefault,
            'is_active' => $t->isActive,
        ];
    }

    private static function tableOut(Table $t): array
    {
        return ['id' => $t->id, 'code' => $t->code, 'capacity' => $t->capacity, 'is_active' => $t->isActive];
    }

    private static function roleOut(Role $r): array
    {
        $permissions = $r->permissionCodes;
        sort($permissions);
        return ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'is_system' => $r->isSystem, 'permissions' => $permissions];
    }

    private static function userOut(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role_id' => $u->roleId,
            'role_code' => $u->roleCode,
            'branch_id' => $u->branchId,
            'is_active' => $u->isActive,
        ];
    }

    // ---------- Configuracion ----------

    public static function getSettings(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.view');
        [$tenant, $settings] = AdminService::getSettings($ctx->tenantId);
        return [
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'business_type' => $tenant->businessType,
            'currency' => $tenant->currency,
            'channels' => $settings->channels,
            'uses_tables' => $settings->usesTables,
            'asks_tip' => $settings->asksTip,
            'tip_percent' => $settings->tipPercent,
        ];
    }

    public static function updateSettings(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        $body = Request::json();
        $changes = [];
        if (array_key_exists('channels', $body) && $body['channels'] !== null) {
            $changes['channels'] = Request::stringList($body, 'channels');
        }
        if (array_key_exists('uses_tables', $body) && $body['uses_tables'] !== null) {
            $changes['uses_tables'] = Request::bool($body, 'uses_tables');
        }
        if (array_key_exists('asks_tip', $body) && $body['asks_tip'] !== null) {
            $changes['asks_tip'] = Request::bool($body, 'asks_tip');
        }
        if (array_key_exists('tip_percent', $body) && $body['tip_percent'] !== null) {
            // Es la sugerencia que ve el cajero, no un cargo: la propina se
            // sigue pudiendo quitar.
            $changes['tip_percent'] = (float) Request::decimalString($body, 'tip_percent');
        }

        // Identidad del restaurante: van aparte porque son columnas de
        // `tenants` y no claves del JSONB de configuracion.
        $profile = [];
        foreach (['name', 'business_type', 'currency'] as $campo) {
            if (array_key_exists($campo, $body) && $body[$campo] !== null) {
                $profile[$campo] = Request::string($body, $campo, 1, 150);
            }
        }
        $currencyConfirmed = array_key_exists('confirm_currency_change', $body)
            && Request::bool($body, 'confirm_currency_change');

        try {
            [$tenant, $settings] = AdminService::updateSettings($ctx->tenantId, $changes, $profile, $currencyConfirmed);
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return [
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'business_type' => $tenant->businessType,
            'currency' => $tenant->currency,
            'channels' => $settings->channels,
            'uses_tables' => $settings->usesTables,
            'asks_tip' => $settings->asksTip,
            'tip_percent' => $settings->tipPercent,
        ];
    }

    // ---------- Sucursales ----------

    public static function listBranches(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.view');
        return array_map(self::branchOut(...), AdminService::listBranches($ctx->tenantId));
    }

    public static function createBranch(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $body = Request::json();
        try {
            $branch = AdminService::createBranch(
                $ctx->tenantId,
                Request::string($body, 'name', 1, 150),
                Request::string($body, 'code', 1, 10),
                Request::optionalString($body, 'timezone', 'America/Bogota'),
                Request::optionalString($body, 'address'),
                Request::optionalString($body, 'phone'),
            );
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }
        return new JsonResponse(self::branchOut($branch), 201);
    }

    public static function setBranchActive(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $body = Request::json();
        try {
            $branch = AdminService::setBranchActive($ctx->tenantId, $params['branch_id'], Request::bool($body, 'is_active'));
        } catch (AdminError $e) {
            throw new ApiException(404, $e->getMessage());
        }
        return self::branchOut($branch);
    }

    // ---------- Horarios ----------

    private static function scheduleOut(BranchScheduleRow $s): array
    {
        return [
            'id' => $s->id,
            'branch_id' => $s->branchId,
            'weekday' => $s->weekday,
            'weekday_name' => ScheduleRules::DIAS[$s->weekday],
            'opens_at' => substr($s->opensAt, 0, 5),
            'closes_at' => substr($s->closesAt, 0, 5),
            // Que cierre antes de abrir significa que cruza la medianoche; la
            // pantalla lo dice en vez de dejar que parezca un error de captura.
            'crosses_midnight' => $s->closesAt < $s->opensAt,
            'channel' => $s->channel,
            'is_active' => $s->isActive,
        ];
    }

    public static function listSchedules(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.view');
        try {
            [$rows, $sinCobertura] = AdminService::listSchedules($ctx->tenantId, $params['branch_id']);
        } catch (AdminError $e) {
            throw new ApiException(404, $e->getMessage());
        }

        return [
            'schedules' => array_map(self::scheduleOut(...), $rows),
            // Con horarios configurados, un canal sin ninguna franja queda
            // cerrado siempre y no da senal hasta que alguien intenta vender.
            'channels_without_windows' => $sinCobertura,
        ];
    }

    public static function createSchedule(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $body = Request::json();

        try {
            $schedule = AdminService::createSchedule(
                $ctx->tenantId,
                $params['branch_id'],
                Request::int($body, 'weekday', min: 0),
                Request::string($body, 'opens_at', 4, 8),
                Request::string($body, 'closes_at', 4, 8),
                Request::optionalString($body, 'channel'),
            );
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::scheduleOut($schedule), 201);
    }

    public static function setScheduleActive(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $body = Request::json();
        try {
            $schedule = AdminService::setScheduleActive($ctx->tenantId, $params['schedule_id'], Request::bool($body, 'is_active'));
        } catch (AdminError $e) {
            throw new ApiException(404, $e->getMessage());
        }
        return self::scheduleOut($schedule);
    }

    public static function deleteSchedule(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        try {
            AdminService::deleteSchedule($ctx->tenantId, $params['schedule_id']);
        } catch (AdminError $e) {
            throw new ApiException(404, $e->getMessage());
        }
        return new JsonResponse(null, 204);
    }

    // ---------- Impuestos ----------

    public static function listTaxRates(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.view');
        return array_map(self::taxRateOut(...), AdminService::listTaxRates($ctx->tenantId));
    }

    public static function createTaxRate(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        $body = Request::json();
        $rate = Request::decimalString($body, 'rate');
        if ((float) $rate < 0 || (float) $rate >= 1) {
            // Fraccion, no porcentaje: 8% se escribe 0.08. El tope evita el
            // error de digitacion mas comun, que es escribir 8 y cobrar 800%.
            throw new ApiException(422, "'rate' debe ser una fraccion decimal entre 0 y 1, p.ej. 0.08 para 8%");
        }
        try {
            $taxRate = AdminService::createTaxRate(
                $ctx->tenantId,
                Request::string($body, 'name', 1, 80),
                $rate,
                array_key_exists('included_in_price', $body) ? Request::bool($body, 'included_in_price') : true,
                array_key_exists('is_default', $body) ? Request::bool($body, 'is_default') : false,
            );
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }
        return new JsonResponse(self::taxRateOut($taxRate), 201);
    }

    public static function setDefaultTaxRate(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        try {
            $taxRate = AdminService::setDefaultTaxRate($ctx->tenantId, $params['tax_rate_id']);
        } catch (AdminError $e) {
            throw new ApiException(404, $e->getMessage());
        }
        return self::taxRateOut($taxRate);
    }

    // ---------- Mesas ----------

    public static function listTables(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.view');
        try {
            $tables = AdminService::listTables($ctx->tenantId, $params['branch_id']);
        } catch (AdminError $e) {
            throw new ApiException(404, $e->getMessage());
        }
        return array_map(self::tableOut(...), $tables);
    }

    /**
     * El salon: cada mesa con el pedido que tenga encima.
     *
     * Pide `orders.view` y no un permiso de configuracion: lo mira quien
     * atiende, no quien administra.
     */
    public static function tableStatus(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');

        try {
            return FloorService::tableStatus($ctx->tenantId, $params['branch_id']);
        } catch (FloorError $e) {
            throw new ApiException(404, $e->getMessage());
        }
    }

    /** Coloca una mesa en el plano del salon. */
    public static function placeTable(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $body = Request::json();

        try {
            FloorService::place(
                $ctx->tenantId,
                $params['branch_id'],
                $params['table_id'],
                Request::int($body, 'pos_x', min: 0),
                Request::int($body, 'pos_y', min: 0),
                Request::string($body, 'shape', 1, 10),
            );
        } catch (FloorError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return ['id' => $params['table_id']];
    }

    public static function createTable(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $body = Request::json();
        try {
            $table = AdminService::createTable(
                $ctx->tenantId,
                $params['branch_id'],
                Request::string($body, 'code', 1, 20),
                Request::int($body, 'capacity', default: 4, min: 1),
            );
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }
        return new JsonResponse(self::tableOut($table), 201);
    }

    // ---------- Roles y permisos ----------

    /**
     * Del catalogo de la plataforma, no de la tabla: es el mismo arreglo que
     * valida los roles, asi que la pantalla no puede ofrecer un permiso que
     * luego se rechace al guardarlo.
     */
    public static function listPermissions(): array
    {
        Deps::require(Deps::getContext(), 'users.manage');
        $salida = [];
        foreach (Permissions::CATALOG as $code => $description) {
            $salida[] = ['code' => $code, 'description' => $description];
        }
        return $salida;
    }

    public static function listRoles(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'users.manage');
        return array_map(self::roleOut(...), AdminService::listRoles($ctx->tenantId));
    }

    public static function createRole(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'users.manage');
        $body = Request::json();
        try {
            $role = AdminService::createRole(
                $ctx->tenantId,
                Request::string($body, 'code', 1, 40),
                Request::string($body, 'name', 1, 100),
                Request::stringList($body, 'permissions'),
            );
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }
        return new JsonResponse(self::roleOut($role), 201);
    }

    public static function setRolePermissions(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'users.manage');
        $body = Request::json();
        try {
            $role = AdminService::setRolePermissions($ctx->tenantId, $params['role_id'], Request::stringList($body, 'permissions'));
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }
        return self::roleOut($role);
    }

    // ---------- Usuarios ----------

    public static function listUsers(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'users.manage');
        return array_map(self::userOut(...), AdminService::listUsers($ctx->tenantId));
    }

    public static function createUser(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'users.manage');
        $body = Request::json();
        try {
            $user = AdminService::createUser(
                $ctx->tenantId,
                Request::string($body, 'name', 1, 150),
                Request::string($body, 'email', 3, 150),
                Request::string($body, 'password', 8, 72),
                Request::uuid($body, 'role_id'),
                Request::optionalUuid($body, 'branch_id'),
            );
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }
        return new JsonResponse(self::userOut($user), 201);
    }

    public static function setUserActive(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'users.manage');
        $body = Request::json();
        try {
            $user = AdminService::setUserActive($ctx->tenantId, $params['user_id'], Request::bool($body, 'is_active'));
        } catch (AdminError $e) {
            throw new ApiException(404, $e->getMessage());
        }
        return self::userOut($user);
    }
}
