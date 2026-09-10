<?php

declare(strict_types=1);

/**
 * Dar de baja un restaurante: borra la empresa entera y todo lo suyo.
 *
 * Es irreversible y no hay pantalla: como el alta, es una operacion de
 * plataforma y no de tenant (ver CLAUDE.md, principio 1). Exige escribir el
 * nombre exacto, que es la unica confirmacion que no se da por accidente.
 *
 * POR QUE UN SCRIPT Y NO UNA MIGRACION CON ON DELETE
 *
 * Un `DELETE FROM tenants` a secas falla, porque siete claves foraneas
 * apuntan a tablas que la cascada intenta vaciar antes que a quien las
 * referencia: NO ACTION se comprueba en cuanto se borra la fila apuntada, no
 * al final de la transaccion, asi que el orden de la cascada decide.
 *
 * Lo tentador es ponerles ON DELETE CASCADE. Pero cinco de las siete son
 * justo las protecciones sobre las que estan construidas las fases 5 y 6:
 *
 *   orders.status_id             un estado con pedidos encima no se borra (F5.2)
 *   order_status_history.status_id   ni aunque ya no queden pedidos ahi   (F5.2)
 *   order_item_modifiers.modifier_id una opcion ya vendida no se borra    (F5.1)
 *   order_items.menu_item_id     los productos se archivan, no se borran  (principio 8)
 *   users.role_id                un rol con usuarios no se borra
 *
 * Con CASCADE, borrar un estado borraria los pedidos que estan en el. Y
 * hacerlas DEFERRABLE tampoco sirve: el error saldria al hacer commit, o sea
 * despues de que el controlador ya respondio 204.
 *
 * Asi que el orden lo pone este script, que es el unico lugar donde borrar de
 * verdad tiene sentido.
 *
 * Uso:
 *   php bin/delete_tenant.php "Pizza Napoli" --confirm="Pizza Napoli"
 */

use App\Core\Database;

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

// A mano y no con getopt(), por lo mismo que create_tenant.php: getopt() deja
// de escanear en el primer argumento que no empiece con "--".
$opts = [];
$objetivo = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $opts[$key] = $value;
    } elseif ($objetivo === null) {
        $objetivo = $arg;
    }
}

if ($objetivo === null) {
    fwrite(STDERR, "Uso: php bin/delete_tenant.php \"Nombre o uuid\" --confirm=\"Nombre exacto\"\n");
    exit(1);
}

// La conexion administrativa: la de la aplicacion corre bajo RLS y con un rol
// que no es dueno de las tablas, justamente para no poder hacer esto.
$pdo = Database::admin();

$stmt = $pdo->prepare(
    "SELECT id, name FROM tenants WHERE name = :objetivo OR id::text = :objetivo"
);
$stmt->execute(['objetivo' => $objetivo]);
$tenants = $stmt->fetchAll();

if (count($tenants) === 0) {
    fwrite(STDERR, "No hay ningun restaurante que se llame o tenga el id '{$objetivo}'\n");
    exit(1);
}
if (count($tenants) > 1) {
    fwrite(STDERR, "Hay {$objetivo} restaurantes con ese nombre; pasa el uuid:\n");
    foreach ($tenants as $t) {
        fwrite(STDERR, "  {$t['id']}  {$t['name']}\n");
    }
    exit(1);
}

$tenant = $tenants[0];

/** Cuenta lo que se va a borrar, para que la confirmacion sea informada. */
$contar = static function (string $sql) use ($pdo, $tenant): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['tenant_id' => $tenant['id']]);
    return (int) $stmt->fetchColumn();
};

$resumen = [
    'sucursales' => $contar('SELECT count(*) FROM branches WHERE tenant_id = :tenant_id'),
    'usuarios' => $contar('SELECT count(*) FROM users WHERE tenant_id = :tenant_id'),
    'productos' => $contar(
        'SELECT count(*) FROM menu_items i JOIN menu_categories c ON c.id = i.category_id WHERE c.tenant_id = :tenant_id'
    ),
    'clientes' => $contar('SELECT count(*) FROM customers WHERE tenant_id = :tenant_id'),
    'pedidos' => $contar('SELECT count(*) FROM orders WHERE tenant_id = :tenant_id'),
    'cobros' => $contar(
        'SELECT count(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE o.tenant_id = :tenant_id'
    ),
    'turnos de caja' => $contar('SELECT count(*) FROM cash_sessions WHERE tenant_id = :tenant_id'),
];

echo "Restaurante: {$tenant['name']}\n";
echo "  id: {$tenant['id']}\n";
foreach ($resumen as $que => $cuantos) {
    printf("  %-16s %d\n", $que, $cuantos);
}

if (($opts['confirm'] ?? null) !== $tenant['name']) {
    fwrite(STDERR, "\nEsto no se puede deshacer. Para confirmarlo, repite el nombre exacto:\n");
    fwrite(STDERR, "  php bin/delete_tenant.php \"{$objetivo}\" --confirm=\"{$tenant['name']}\"\n");
    exit(1);
}

/**
 * El orden importa: cada paso quita a quien referencia antes de que la
 * cascada del tenant llegue a lo referenciado.
 *
 * Los pedidos primero, porque de ellos cuelgan las lineas, la bitacora, los
 * cobros y el domicilio — y son esos los que retienen a los estados, a los
 * modificadores, a los productos y a las zonas. Despues el menu y los grupos
 * de opciones, que retienen a los impuestos. Los usuarios antes que los
 * roles. Lo demas se va con el tenant.
 */
$pasos = [
    'pedidos y todo lo que cuelga de ellos' => 'DELETE FROM orders WHERE tenant_id = :tenant_id',
    'turnos de caja' => 'DELETE FROM cash_sessions WHERE tenant_id = :tenant_id',
    'menu' => 'DELETE FROM menu_categories WHERE tenant_id = :tenant_id',
    'grupos de opciones' => 'DELETE FROM modifier_groups WHERE tenant_id = :tenant_id',
    'usuarios' => 'DELETE FROM users WHERE tenant_id = :tenant_id',
    'el restaurante' => 'DELETE FROM tenants WHERE id = :tenant_id',
];

$pdo->beginTransaction();
try {
    foreach ($pasos as $que => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tenant_id' => $tenant['id']]);
        printf("  borrado: %-38s %d fila(s)\n", $que, $stmt->rowCount());
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "No se pudo borrar: {$e->getMessage()}\n");
    fwrite(STDERR, "No se borro nada: la transaccion se deshizo entera.\n");
    exit(1);
}

echo "\nRestaurante '{$tenant['name']}' dado de baja.\n";
