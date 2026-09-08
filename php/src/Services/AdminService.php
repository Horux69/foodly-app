<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Domain\SettingsError;
use App\Domain\TenantSettings;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Table;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\BranchRepository;
use App\Repositories\RoleRepository;
use App\Repositories\TableRepository;
use App\Repositories\TaxRateRepository;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;
use PDO;

/**
 * Casos de uso de administracion: configuracion, sucursales, usuarios y roles.
 *
 * Todo lo que aqui se crea queda amarrado al tenant del token. Los ids que
 * llegan del cliente (rol, sucursal) se verifican contra ese tenant antes de
 * usarse: asignar un rol de otra empresa seria una escalada de privilegios.
 */
final class AdminService
{
    private static function pdo(): PDO
    {
        return Database::app();
    }

    private static function tenant(TenantRepository $repo, string $tenantId): Tenant
    {
        $tenant = $repo->get($tenantId);
        if ($tenant === null) {
            throw new AdminError('El tenant no existe');
        }
        return $tenant;
    }

    // ---------- Configuracion (modulo 9) ----------

    /** @return array{0: Tenant, 1: TenantSettings} */
    public static function getSettings(string $tenantId): array
    {
        $tenant = self::tenant(new TenantRepository(self::pdo()), $tenantId);
        return [$tenant, TenantSettings::parse($tenant->settings, $tenant->businessType)];
    }

    /**
     * @param array<mixed> $changes
     * @return array{0: Tenant, 1: TenantSettings}
     */
    public static function updateSettings(string $tenantId, array $changes): array
    {
        $repo = new TenantRepository(self::pdo());
        $tenant = self::tenant($repo, $tenantId);

        // Se valida el resultado del merge y no solo el parche: activar el
        // canal 'table' sin tocar uses_tables debe chocar contra el valor ya
        // guardado.
        $merged = array_merge($tenant->settings, $changes);
        try {
            TenantSettings::validate($merged);
        } catch (SettingsError $e) {
            throw new AdminError($e->getMessage());
        }

        $updated = $repo->updateSettings($tenantId, $merged);
        return [$updated, TenantSettings::parse($updated->settings, $updated->businessType)];
    }

    // ---------- Sucursales ----------

    /** @return Branch[] */
    public static function listBranches(string $tenantId): array
    {
        return (new BranchRepository(self::pdo()))->listForTenant($tenantId);
    }

    public static function createBranch(
        string $tenantId,
        string $name,
        string $code,
        string $timezone = 'America/Bogota',
        ?string $address = null,
        ?string $phone = null,
    ): Branch {
        $repo = new BranchRepository(self::pdo());
        if ($repo->getByCode($tenantId, $code) !== null) {
            throw new AdminError("Ya existe una sucursal con el codigo '{$code}'");
        }
        return $repo->create($tenantId, $name, $code, $timezone, $address, $phone);
    }

    public static function setBranchActive(string $tenantId, string $branchId, bool $isActive): Branch
    {
        $repo = new BranchRepository(self::pdo());
        if ($repo->get($tenantId, $branchId) === null) {
            throw new AdminError('La sucursal no existe para este tenant');
        }
        return $repo->setActive($tenantId, $branchId, $isActive);
    }

    // ---------- Impuestos ----------

    /** @return TaxRate[] */
    public static function listTaxRates(string $tenantId): array
    {
        return (new TaxRateRepository(self::pdo()))->listForTenant($tenantId);
    }

    public static function createTaxRate(
        string $tenantId,
        string $name,
        string $rate,
        bool $includedInPrice = true,
        bool $isDefault = false,
    ): TaxRate {
        $repo = new TaxRateRepository(self::pdo());
        if ($isDefault) {
            $repo->clearDefault($tenantId);
        }
        return $repo->create($tenantId, $name, $rate, $includedInPrice, $isDefault);
    }

    public static function setDefaultTaxRate(string $tenantId, string $taxRateId): TaxRate
    {
        $repo = new TaxRateRepository(self::pdo());
        if ($repo->get($tenantId, $taxRateId) === null) {
            throw new AdminError('El impuesto no existe para este tenant');
        }
        $repo->clearDefault($tenantId);
        return $repo->setDefault($tenantId, $taxRateId);
    }

    // ---------- Mesas ----------

    private static function ownedBranch(BranchRepository $repo, string $tenantId, string $branchId): Branch
    {
        $branch = $repo->get($tenantId, $branchId);
        if ($branch === null) {
            throw new AdminError('La sucursal no existe para este tenant');
        }
        return $branch;
    }

    /** @return Table[] */
    public static function listTables(string $tenantId, string $branchId): array
    {
        $branch = self::ownedBranch(new BranchRepository(self::pdo()), $tenantId, $branchId);
        return (new TableRepository(self::pdo()))->listForBranch($branch->id);
    }

    public static function createTable(string $tenantId, string $branchId, string $code, int $capacity): Table
    {
        $pdo = self::pdo();
        $branch = self::ownedBranch(new BranchRepository($pdo), $tenantId, $branchId);

        $tenant = self::tenant(new TenantRepository($pdo), $tenantId);
        $settings = TenantSettings::parse($tenant->settings, $tenant->businessType);
        if (!$settings->usesTables) {
            throw new AdminError('Este restaurante no maneja mesas: activalo en la configuracion');
        }

        $tables = new TableRepository($pdo);
        if ($tables->getByCode($branch->id, $code) !== null) {
            throw new AdminError("Ya existe una mesa con el codigo '{$code}' en esta sucursal");
        }
        return $tables->create($branch->id, $code, $capacity);
    }

    // ---------- Roles ----------

    /** @return Role[] */
    public static function listRoles(string $tenantId): array
    {
        return (new RoleRepository(self::pdo()))->listForTenant($tenantId);
    }

    /**
     * @param string[] $codes
     * @return string[]
     */
    private static function resolvePermissions(RoleRepository $repo, array $codes): array
    {
        $found = array_map(static fn ($p) => $p->code, $repo->permissionsByCodes($codes));
        $unknown = array_values(array_diff($codes, $found));
        if ($unknown !== []) {
            throw new AdminError('Permisos desconocidos: ' . implode(', ', $unknown));
        }
        return $found;
    }

    /** @param string[] $permissions */
    public static function createRole(string $tenantId, string $code, string $name, array $permissions): Role
    {
        $repo = new RoleRepository(self::pdo());
        if ($repo->getByCode($tenantId, $code) !== null) {
            throw new AdminError("Ya existe un rol con el codigo '{$code}'");
        }

        $resolved = self::resolvePermissions($repo, $permissions);
        $role = $repo->create($tenantId, $code, $name);
        $repo->setPermissions($role->id, $resolved);
        return $repo->get($tenantId, $role->id);
    }

    /** @param string[] $permissions */
    public static function setRolePermissions(string $tenantId, string $roleId, array $permissions): Role
    {
        $repo = new RoleRepository(self::pdo());
        $role = $repo->get($tenantId, $roleId);
        if ($role === null) {
            throw new AdminError('El rol no existe para este tenant');
        }
        if ($role->isSystem) {
            // El rol admin es la salida de emergencia del tenant: si se le
            // quitan permisos, nadie queda con users.manage para devolverselos.
            throw new AdminError('Los roles de sistema no se pueden modificar');
        }

        $resolved = self::resolvePermissions($repo, $permissions);
        $repo->setPermissions($roleId, $resolved);
        return $repo->get($tenantId, $roleId);
    }

    // ---------- Usuarios ----------

    /** @return User[] */
    public static function listUsers(string $tenantId): array
    {
        $pdo = self::pdo();
        return (new UserRepository($pdo, new RoleRepository($pdo)))->listForTenant($tenantId);
    }

    public static function createUser(
        string $tenantId,
        string $name,
        string $email,
        string $password,
        string $roleId,
        ?string $branchId = null,
    ): User {
        $pdo = self::pdo();
        $users = new UserRepository($pdo, new RoleRepository($pdo));
        if ($users->getByEmailInTenant($tenantId, $email) !== null) {
            throw new AdminError("Ya existe un usuario con el email '{$email}'");
        }

        if ((new RoleRepository($pdo))->get($tenantId, $roleId) === null) {
            throw new AdminError('El rol no existe para este tenant');
        }

        if ($branchId !== null && (new BranchRepository($pdo))->get($tenantId, $branchId) === null) {
            throw new AdminError('La sucursal no existe para este tenant');
        }

        return $users->create($tenantId, $roleId, $branchId, $name, $email, Security::hashPassword($password));
    }

    public static function setUserActive(string $tenantId, string $userId, bool $isActive): User
    {
        $pdo = self::pdo();
        $users = new UserRepository($pdo, new RoleRepository($pdo));
        if ($users->get($tenantId, $userId) === null) {
            throw new AdminError('El usuario no existe para este tenant');
        }
        return $users->setActive($tenantId, $userId, $isActive);
    }
}
