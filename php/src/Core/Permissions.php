<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Catalogo global de permisos del sistema, y la unica fuente de verdad de
 * cuales existen.
 *
 * Los permisos son fijos (los define la plataforma); los roles que los
 * agrupan son configurables por tenant. Esto es lo que valida un rol, lo que
 * responde GET /permissions y lo que comprueba Deps::require.
 *
 * La tabla `permissions` de la base sigue existiendo porque `role_permissions`
 * apunta a ella, pero es el destino del join, no el catalogo: las dos se
 * mantienen iguales y `tests/Core/PermissionsTest.php` compara este arreglo
 * contra los INSERT de db/. Antes no era asi —este arreglo no se usaba en
 * ninguna parte y le faltaban los dos permisos de clientes que la migracion
 * 003 si habia insertado—, y un catalogo que contradice al codigo es peor
 * que ninguno.
 */
final class Permissions
{
    /**
     * La descripcion es lo que se lee en la pantalla de roles, asi que se
     * escribe para quien la va a leer y no como nota tecnica.
     */
    public const CATALOG = [
        // Configuracion
        'settings.view' => 'Ver la configuración del restaurante',
        'settings.edit' => 'Editar la configuración del restaurante',
        'branches.manage' => 'Gestionar sucursales',
        'users.manage' => 'Gestionar usuarios y roles',
        // Menu
        'menu.view' => 'Ver el menú',
        'menu.edit' => 'Crear y editar productos y categorías',
        'menu.availability' => 'Marcar productos como agotados',
        // Pedidos
        'orders.create' => 'Crear pedidos',
        'orders.view' => 'Ver pedidos',
        'orders.edit' => 'Modificar pedidos abiertos',
        'orders.cancel' => 'Anular pedidos',
        'orders.advance_kitchen' => 'Avanzar estados de cocina',
        'orders.discount' => 'Aplicar descuentos',
        // Caja
        'payments.register' => 'Registrar cobros',
        'payments.refund' => 'Reembolsar cobros',
        'cash.close' => 'Cerrar turno y hacer el arqueo',
        // Aparte de payments.register: cobrar es recibir plata de una venta,
        // sacarla del cajon es otra cosa y suele autorizarla otra persona.
        'cash.movements' => 'Registrar entradas y salidas de efectivo',
        // Domicilios
        'delivery.assign' => 'Asignar repartidores',
        'delivery.complete' => 'Confirmar entregas',
        // Clientes
        'customers.view' => 'Ver la base de clientes y su historial',
        'customers.manage' => 'Editar los datos de un cliente',
        // Reportes
        'reports.view' => 'Ver reportes',
    ];

    /** @return string[] */
    public static function codes(): array
    {
        return array_keys(self::CATALOG);
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, self::CATALOG);
    }

    /**
     * Un permiso que no esta en el catalogo es un error de programacion, no
     * una peticion mal hecha: quien lo escribio mal deja a todo el mundo
     * fuera de esa pantalla sin que nadie vea la causa. Se rompe ruidoso.
     */
    public static function assertKnown(string ...$codes): void
    {
        foreach ($codes as $code) {
            if (!self::exists($code)) {
                throw new \LogicException("Permiso fuera del catalogo: {$code}");
            }
        }
    }
}
