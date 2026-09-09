<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Config;
use App\Core\Database;
use App\Core\Security;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Repositories\BranchRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\TenantProvisioning;
use PHPUnit\Framework\TestCase;

/**
 * Base de las pruebas que hablan con un Postgres de verdad.
 *
 * Existen porque la suite de dominio no ve nada de lo que pasa entre capas:
 * en una sola sesion se rompieron dos veces el alta de restaurantes y el
 * tablero de cocina con la suite en verde. Aqui se ejercita el camino
 * completo —servicio, repositorio, SQL, RLS— que es donde eso se nota.
 *
 * Si no hay base de pruebas configurada, la suite entera se salta y el resto
 * corre igual: nadie deberia necesitar Postgres para correr las de dominio.
 * Para tenerla:
 *
 *   ./scripts/setup-test-db.sh
 *   # y en php/.env, TEST_DATABASE_URL y TEST_APP_DATABASE_URL
 *
 * Cada prueba trabaja sobre su propia empresa recien creada. No se limpia
 * entre pruebas a proposito: RLS ya las aisla, y una base que queda con lo
 * que dejo la corrida anterior es justo donde se ven los problemas de
 * aislamiento.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $preparada = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$preparada) {
            return;
        }

        $admin = $_ENV['TEST_DATABASE_URL'] ?? null;
        $app = $_ENV['TEST_APP_DATABASE_URL'] ?? null;
        if ($admin === null || $app === null) {
            self::markTestSkipped(
                'Sin base de pruebas: define TEST_DATABASE_URL y TEST_APP_DATABASE_URL '
                . '(ver scripts/setup-test-db.sh)'
            );
        }

        // Config y Database son estaticos y cachean: se apunta al entorno de
        // pruebas antes de que nadie los toque, y por eso este bloque corre
        // una sola vez para toda la corrida.
        $_ENV['DATABASE_URL'] = $admin;
        $_ENV['APP_DATABASE_URL'] = $app;
        $_ENV['SECRET_KEY'] = $_ENV['SECRET_KEY'] ?? str_repeat('k', 64);

        try {
            Database::admin()->query('SELECT 1');
            Database::app()->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('No se pudo conectar a la base de pruebas: ' . $e->getMessage());
        }

        self::$preparada = true;
    }

    protected function tearDown(): void
    {
        // Cada prueba deja la conexion de la aplicacion como la encontro: la
        // transaccion la abre setTenantContext y en produccion la cierra el
        // front controller.
        Database::endAppTransaction(success: true);
        parent::tearDown();
    }

    /**
     * Una empresa nueva, lista para operar, con su sucursal y su admin.
     *
     * Es el mismo camino que `bin/create_tenant.php`, asi que cada prueba lo
     * ejercita de paso — que es exactamente lo que estaba roto y nadie vio.
     *
     * @return array{0: Tenant, 1: Branch, 2: User, 3: string} empresa, sucursal, admin y su contrasena
     */
    protected function nuevaEmpresa(string $businessType = 'fast_food'): array
    {
        $sufijo = bin2hex(random_bytes(4));
        $password = 'clave-de-prueba-larga';

        $pdo = Database::admin();
        $pdo->beginTransaction();
        try {
            $tenant = TenantProvisioning::createTenant("Prueba {$sufijo}", $businessType);
            $branch = (new BranchRepository($pdo))->create($tenant->id, 'Sede', 'SED', 'America/Bogota', null, null);

            $roles = new RoleRepository($pdo);
            $admin = $roles->getByCode($tenant->id, 'admin');
            $user = (new UserRepository($pdo, $roles))->create(
                $tenant->id,
                $admin->id,
                $branch->id,
                'Admin de prueba',
                "admin-{$sufijo}@prueba.test",
                Security::hashPassword($password),
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // A partir de aqui las pruebas hablan como la aplicacion: por la
        // conexion app(), bajo RLS y con el tenant fijado.
        Database::setTenantContext(Database::app(), $tenant->id);

        return [$tenant, $branch, $user, $password];
    }

    /** Cambia el tenant de la conexion de la aplicacion, como haria otra request. */
    protected function comoEmpresa(string $tenantId): void
    {
        Database::endAppTransaction(success: true);
        Database::setTenantContext(Database::app(), $tenantId);
    }

    protected function config(): Config
    {
        return Config::get();
    }
}
