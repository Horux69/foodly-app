<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Conexiones a Postgres. Dos roles, mismo principio que la version Python:
 *
 * - app(): rol resto_app, NO dueño de las tablas. Postgres solo aplica RLS a
 *   quien no es dueño; si esta conexion fuera la dueña, las politicas se
 *   saltearian en silencio y dejarian de proteger nada. Se usa para servir
 *   cada request.
 * - admin(): conexion administrativa, dueña de las tablas. Solo para lo que
 *   es inherentemente previo a un tenant: migraciones y alta de empresas.
 *   Nunca para servir una request.
 */
final class Database
{
    private static ?PDO $app = null;
    private static ?PDO $admin = null;

    public static function app(): PDO
    {
        if (self::$app === null) {
            $config = Config::get();
            self::$app = self::connect($config->appDatabaseUrl ?? $config->databaseUrl);
        }
        return self::$app;
    }

    public static function admin(): PDO
    {
        if (self::$admin === null) {
            self::$admin = self::connect(Config::get()->databaseUrl);
        }
        return self::$admin;
    }

    private static function connect(string $dsn): PDO
    {
        $pdo = new PDO($dsn, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }

    /**
     * Activa el aislamiento por Row Level Security para la conexion actual.
     *
     * Debe llamarse en cada request autenticado, antes de cualquier otra
     * consulta. Sin esto, las politicas RLS de Postgres no devuelven filas.
     * set_config (no SET LOCAL de texto) para poder bindear el parametro con
     * seguridad, igual que hace set_tenant_context en Python.
     *
     * true en el tercer argumento de set_config = valido solo para la
     * transaccion/sesion actual, exactamente como SET LOCAL.
     */
    public static function setTenantContext(PDO $pdo, string $tenantId): void
    {
        $stmt = $pdo->prepare("SELECT set_config('app.current_tenant', :tid, true)");
        $stmt->execute(['tid' => $tenantId]);
    }
}
