<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Security;
use App\Domain\PasswordError;
use App\Domain\PasswordRules;
use App\Domain\SessionError;
use App\Domain\SessionRenewal;
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
    /**
     * Cuantas empresas se prueban cuando el correo se repite y no dieron el
     * slug. Cada intento cuesta un bcrypt (~100 ms), asi que el limite existe
     * para que un correo repetido en muchas empresas no vuelva el login lento
     * ni sirva para agotar el servidor.
     */
    private const MAX_CANDIDATAS = 5;

    public static function login(string $email, string $password, ?string $slug = null): string
    {
        $pdo = Database::app();
        $tenants = new TenantRepository($pdo);

        // Con slug, la empresa esta decidida antes de mirar la contrasena.
        if ($slug !== null && $slug !== '') {
            $tenantId = $tenants->tenantForLoginBySlug($email, $slug);
            if ($tenantId === null) {
                throw new AuthError('Credenciales invalidas');
            }
            return self::emitirToken($pdo, $tenantId, $email, $password);
        }

        $candidatas = $tenants->tenantsForLogin($email);
        if ($candidatas === []) {
            throw new AuthError('Credenciales invalidas');
        }
        if (count($candidatas) > self::MAX_CANDIDATAS) {
            throw new AmbiguousLoginError('Ese correo esta en varios restaurantes: indica cual');
        }

        /**
         * Se prueba la contrasena contra cada empresa candidata y recien
         * despues se decide.
         *
         * Podria bastar con pedir el slug apenas hay mas de una candidata,
         * pero eso le contaria a cualquiera que escriba un correo en cuantas
         * empresas existe. Asi, la ambiguedad solo se le revela a quien ya
         * sabe la contrasena — y en el caso normal, dos personas distintas
         * con el mismo correo en dos restaurantes, cada una entra sin
         * escribir nada mas.
         */
        $aciertos = [];
        foreach ($candidatas as $tenantId) {
            Database::setTenantContext($pdo, $tenantId);
            $user = (new UserRepository($pdo, new RoleRepository($pdo)))->getByEmail($email);
            if ($user !== null && Security::verifyPassword($password, $user->passwordHash)) {
                $aciertos[] = $tenantId;
            }
        }

        if ($aciertos === []) {
            throw new AuthError('Credenciales invalidas');
        }
        if (count($aciertos) > 1) {
            throw new AmbiguousLoginError(
                'Ese correo y esa contrasena sirven en mas de un restaurante: indica en cual quieres entrar'
            );
        }

        return self::emitirToken($pdo, $aciertos[0], $email, $password);
    }

    private static function emitirToken(\PDO $pdo, string $tenantId, string $email, string $password): string
    {
        Database::setTenantContext($pdo, $tenantId);

        $user = (new UserRepository($pdo, new RoleRepository($pdo)))->getByEmail($email);
        if ($user === null || !Security::verifyPassword($password, $user->passwordHash)) {
            throw new AuthError('Credenciales invalidas');
        }

        return Security::createAccessToken($user->id, $user->tenantId, $user->branchId, $user->roleCode, $user->permissionCodes);
    }

    /**
     * Emite un token nuevo para una sesion que sigue viva.
     *
     * Sin esto el token vence a las ocho horas y api.js manda a la pantalla
     * de ingreso en mitad de un pedido. Se releen el rol y los permisos, asi
     * que un cambio de rol entra en vigor sin volver a entrar — antes habia
     * que cerrar sesion para que se notara.
     *
     * El limite de cuanto puede vivir la sesion lo pone
     * Domain\SessionRenewal contra el `auth_time` que se arrastra: renovar
     * no corre ese limite.
     */
    public static function refresh(string $tenantId, string $userId, int $authTime): string
    {
        try {
            SessionRenewal::ensureRenewable($authTime, time());
        } catch (SessionError $e) {
            throw new AuthError($e->getMessage());
        }

        $pdo = Database::app();
        $user = (new UserRepository($pdo, new RoleRepository($pdo)))->get($tenantId, $userId);
        if ($user === null || !$user->isActive) {
            throw new AuthError('La cuenta ya no esta activa');
        }

        return Security::createAccessToken(
            $user->id,
            $user->tenantId,
            $user->branchId,
            $user->roleCode,
            $user->permissionCodes,
            $authTime,
        );
    }

    /**
     * Cambiar la propia contrasena.
     *
     * Se exige la actual aunque la sesion ya este abierta: una tableta
     * desatendida en el mostrador es el caso comun, y sin esa comprobacion
     * cualquiera que pase deja al dueno fuera de su propio sistema.
     *
     * Los tokens ya emitidos siguen valiendo hasta que venzan: no hay
     * version de sesion en el modelo. Para cortar todo de inmediato hay que
     * rotar SECRET_KEY, que echa a todo el mundo.
     */
    public static function changePassword(string $tenantId, string $userId, string $actual, string $nueva): void
    {
        $pdo = Database::app();
        $repo = new UserRepository($pdo, new RoleRepository($pdo));
        $user = $repo->get($tenantId, $userId);
        if ($user === null) {
            throw new AuthError('El usuario ya no existe');
        }
        if (!Security::verifyPassword($actual, $user->passwordHash)) {
            throw new AuthError('La contrasena actual no es correcta');
        }

        try {
            PasswordRules::validate($nueva, $actual);
        } catch (PasswordError $e) {
            throw new AuthError($e->getMessage());
        }

        $repo->setPasswordHash($user->id, Security::hashPassword($nueva));
    }

    /**
     * Quien es el usuario, como opera su restaurante y entre que sucursales
     * puede moverse.
     *
     * Las sucursales van aqui y no en /branches a proposito: ese endpoint es
     * de administracion y pide 'settings.view', pero un cajero de una
     * cadena tambien necesita saber en cual esta parado. Son nombres de
     * sucursal de su propia empresa, no hay nada que ocultarle.
     *
     * @return array{0: User, 1: ?Branch, 2: Tenant, 3: TenantSettings, 4: Branch[]}
     */
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

        $branches = new BranchRepository($pdo);
        $branch = $user->branchId !== null ? $branches->get($tenantId, $user->branchId) : null;
        $settings = TenantSettings::parse($tenant->settings, $tenant->businessType);

        // Solo las activas: una sucursal dada de baja no es un lugar donde
        // se pueda seguir vendiendo.
        $operables = array_values(array_filter($branches->listForTenant($tenantId), static fn ($b) => $b->isActive));

        return [$user, $branch, $tenant, $settings, $operables];
    }
}
