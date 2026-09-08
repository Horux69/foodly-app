<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Domain\TenantSettings;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\BranchRepository;
use App\Repositories\RoleRepository;
use App\Repositories\TenantRepository;
use App\Repositories\UserRepository;

final class AuthService
{
    /**
     * Autentica y emite el JWT. El tenant_id se resuelve aqui, a partir del
     * usuario encontrado, y de ahi en adelante viaja siempre dentro del
     * token (nunca se vuelve a pedir al cliente, ver Api\Deps::getContext).
     *
     * Dos pasos a proposito: primero se averigua el tenant del email (unica
     * consulta que cruza empresas, acotada a devolver solo eso), y recien
     * despues se busca al usuario ya con el contexto puesto, bajo RLS.
     */
    public static function login(string $email, string $password): string
    {
        $pdo = Database::app();
        $tenantId = (new TenantRepository($pdo))->findTenantForLogin($email);
        if ($tenantId === null) {
            throw new AuthError('Credenciales invalidas');
        }
        Database::setTenantContext($pdo, $tenantId);

        $user = (new UserRepository($pdo, new RoleRepository($pdo)))->getByEmail($email);
        if ($user === null || !Security::verifyPassword($password, $user->passwordHash)) {
            throw new AuthError('Credenciales invalidas');
        }

        return Security::createAccessToken($user->id, $user->tenantId, $user->branchId, $user->roleCode, $user->permissionCodes);
    }

    /** @return array{0: User, 1: ?Branch, 2: Tenant, 3: TenantSettings} */
    public static function getMe(string $tenantId, string $userId): array
    {
        $pdo = Database::app();
        $user = (new UserRepository($pdo, new RoleRepository($pdo)))->get($tenantId, $userId);
        if ($user === null) {
            throw new AuthError('El usuario ya no existe');
        }

        $tenant = (new TenantRepository($pdo))->get($tenantId);
        if ($tenant === null) {
            throw new AuthError('El tenant ya no existe');
        }

        $branch = $user->branchId !== null ? (new BranchRepository($pdo))->get($tenantId, $user->branchId) : null;
        $settings = TenantSettings::parse($tenant->settings, $tenant->businessType);

        return [$user, $branch, $tenant, $settings];
    }
}
