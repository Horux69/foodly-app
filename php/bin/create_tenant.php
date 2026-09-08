<?php

declare(strict_types=1);

/**
 * Alta de un restaurante nuevo en la plataforma.
 *
 * Crear un tenant es una operacion de plataforma, no de tenant: no existe
 * todavia un token del cual sacar el tenant_id, y el modelo de seguridad no
 * contempla un usuario que cruce empresas (ver CLAUDE.md, principio 1). Por
 * eso vive en un script y no en un endpoint — equivalente PHP de
 * scripts/create_tenant.py.
 *
 * Uso:
 *   php bin/create_tenant.php "Pizza Napoli" \
 *       --business-type=table_service \
 *       --branch="Sede Norte" --branch-code=NOR \
 *       --admin-email=dueno@napoli.com --admin-password="una-clave-larga"
 */

use App\Core\Database;
use App\Core\Security;
use App\Domain\TenantSettings;
use App\Repositories\BranchRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\TenantProvisioning;
use App\Services\TenantProvisioningError;

require dirname(__DIR__) . '/vendor/autoload.php';

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

// Parseo manual en vez de getopt(): getopt() deja de escanear en el primer
// argumento que no empiece con "--", asi que con el nombre del restaurante
// primero (el uso natural: `create_tenant.php "Nombre" --branch=...`)
// ignora todas las opciones que vengan despues. Sin esa limitacion, a mano.
$opts = [];
$name = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $opts[$key] = $value;
    } elseif ($name === null) {
        $name = $arg;
    }
}

$missing = array_filter(['branch', 'branch-code', 'admin-email', 'admin-password'], fn ($k) => !isset($opts[$k]));
if ($name === null || $missing !== []) {
    fwrite(STDERR, "Uso: php bin/create_tenant.php \"Nombre\" --branch=X --branch-code=Y --admin-email=Z --admin-password=W\n");
    exit(1);
}

$businessType = $opts['business-type'] ?? 'fast_food';
if (!in_array($businessType, TenantSettings::BUSINESS_TYPES, true)) {
    $valid = implode(', ', TenantSettings::BUSINESS_TYPES);
    fwrite(STDERR, "--business-type debe ser uno de: {$valid}\n");
    exit(1);
}

$adminPassword = $opts['admin-password'];
if (strlen($adminPassword) < 8) {
    fwrite(STDERR, "La clave del administrador debe tener al menos 8 caracteres\n");
    exit(1);
}

// Todo el alta en una sola transaccion: un restaurante sin usuario con el
// cual entrar no sirve de nada, asi que si algo falla no queda nada creado.
$pdo = Database::admin();
$pdo->beginTransaction();

try {
    $tenant = TenantProvisioning::createTenant(
        $name,
        $businessType,
        $opts['currency'] ?? 'COP',
    );

    $branch = (new BranchRepository($pdo))->create(
        $tenant->id,
        $opts['branch'],
        $opts['branch-code'],
        $opts['timezone'] ?? 'America/Bogota',
        null,
        null,
    );

    $adminRole = (new RoleRepository($pdo))->getByCode($tenant->id, 'admin');
    if ($adminRole === null) {
        $pdo->rollBack();
        fwrite(STDERR, "El aprovisionamiento no dejo un rol admin; se aborta\n");
        exit(1);
    }

    (new UserRepository($pdo, new RoleRepository($pdo)))->create(
        $tenant->id,
        $adminRole->id,
        $branch->id,
        $opts['admin-name'] ?? 'Administrador',
        $opts['admin-email'],
        Security::hashPassword($adminPassword),
    );
    $pdo->commit();
} catch (TenantProvisioningError|\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "No se pudo crear el restaurante: {$e->getMessage()}\n");
    exit(1);
}

echo "Restaurante '{$name}' creado\n";
echo "  tenant_id : {$tenant->id}\n";
echo "  sucursal  : {$branch->name} ({$branch->code})\n";
echo "  admin     : {$opts['admin-email']}\n";
