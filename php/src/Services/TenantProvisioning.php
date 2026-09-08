<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\SettingsError;
use App\Domain\TenantSettings;
use App\Models\Tenant;
use App\Repositories\OrderStatusRepository;
use App\Repositories\RoleRepository;
use App\Repositories\TaxRateRepository;
use App\Repositories\TenantRepository;
use PDO;

/**
 * Aprovisionamiento de un tenant nuevo.
 *
 * Al crear un tenant hay que sembrar su configuracion minima para operar:
 * estados de pedido, un rol admin con todos los permisos, y un impuesto por
 * defecto. El flujo de estados varia segun business_type; el rol y el
 * impuesto no. Si un modelo de negocio nuevo exige tocar codigo aqui en vez
 * de agregar un preset, el diseño esta fallando (ver docs/multi-tenancy.md).
 *
 * Corre siempre sobre la conexion administrativa: crear una empresa es
 * previo a que exista tenant alguno, y RLS no tiene forma de dejar pasar un
 * INSERT en tenants sin un tenant_id ya fijado (ver CLAUDE.md, principio 3).
 */
final class TenantProvisioningError extends \RuntimeException
{
}

final class TenantProvisioning
{
    /**
     * [code, name, category, sort_order, is_initial, is_final][]
     * [from_code, to_code, required_permission|null][]
     *
     * @var array<string, array{statuses: array<int, array{0:string,1:string,2:string,3:int,4:bool,5:bool}>, transitions: array<int, array{0:string,1:string,2:?string}>}>
     */
    private const STATUS_PRESETS = [
        'fast_food' => [
            'statuses' => [
                ['pending', 'Pendiente', 'new', 1, true, false],
                ['paid', 'Pagado', 'new', 2, false, false],
                ['preparing', 'Preparacion', 'kitchen', 3, false, false],
                ['ready', 'Listo', 'ready', 4, false, false],
                ['delivered', 'Entregado', 'completed', 5, false, true],
                ['cancelled', 'Cancelado', 'cancelled', 9, false, true],
            ],
            'transitions' => [
                ['pending', 'paid', 'payments.register'],
                ['paid', 'preparing', 'orders.advance_kitchen'],
                ['preparing', 'ready', 'orders.advance_kitchen'],
                ['ready', 'delivered', 'orders.advance_kitchen'],
                ['pending', 'cancelled', 'orders.cancel'],
                ['paid', 'cancelled', 'orders.cancel'],
            ],
        ],
        'table_service' => [
            'statuses' => [
                ['open', 'Abierto', 'new', 1, true, false],
                ['preparing', 'Preparacion', 'kitchen', 2, false, false],
                ['ready', 'Listo', 'ready', 3, false, false],
                ['served', 'Servido', 'completed', 4, false, true],
                ['cancelled', 'Cancelado', 'cancelled', 9, false, true],
            ],
            'transitions' => [
                ['open', 'preparing', 'orders.advance_kitchen'],
                ['preparing', 'ready', 'orders.advance_kitchen'],
                ['ready', 'served', 'orders.advance_kitchen'],
                ['open', 'cancelled', 'orders.cancel'],
                ['preparing', 'cancelled', 'orders.cancel'],
            ],
        ],
        'delivery' => [
            'statuses' => [
                ['pending', 'Pendiente', 'new', 1, true, false],
                ['paid', 'Pagado', 'new', 2, false, false],
                ['preparing', 'Preparacion', 'kitchen', 3, false, false],
                ['ready', 'Listo', 'ready', 4, false, false],
                ['in_transit', 'En camino', 'in_transit', 5, false, false],
                ['delivered', 'Entregado', 'completed', 6, false, true],
                ['cancelled', 'Cancelado', 'cancelled', 9, false, true],
            ],
            'transitions' => [
                ['pending', 'paid', 'payments.register'],
                ['paid', 'preparing', 'orders.advance_kitchen'],
                ['preparing', 'ready', 'orders.advance_kitchen'],
                ['ready', 'in_transit', 'delivery.assign'],
                ['in_transit', 'delivered', 'delivery.complete'],
                ['pending', 'cancelled', 'orders.cancel'],
                ['paid', 'cancelled', 'orders.cancel'],
            ],
        ],
    ];

    /** Siembra estados, rol admin e impuesto por defecto para un tenant recien creado. */
    public static function provisionTenant(PDO $pdo, string $tenantId, string $businessType): void
    {
        $preset = self::STATUS_PRESETS[$businessType] ?? self::STATUS_PRESETS['fast_food'];
        $statusRepo = new OrderStatusRepository($pdo);

        $idByCode = [];
        foreach ($preset['statuses'] as [$code, $name, $category, $sortOrder, $isInitial, $isFinal]) {
            $idByCode[$code] = $statusRepo->insertStatus($tenantId, $code, $name, $category, $sortOrder, $isInitial, $isFinal);
        }

        foreach ($preset['transitions'] as [$fromCode, $toCode, $permission]) {
            $statusRepo->insertTransition($idByCode[$fromCode], $idByCode[$toCode], $permission);
        }

        (new TaxRateRepository($pdo))->create($tenantId, 'Impuesto general', '0', true, true);

        $roles = new RoleRepository($pdo);
        // is_system=true: es la salida de emergencia del tenant (ver
        // AdminService::setRolePermissions), asi que no puede quedar como un
        // rol mas que alguien edite hasta dejarlo sin permisos.
        $adminRole = $roles->create($tenantId, 'admin', 'Administrador', isSystem: true);
        $allPermissionCodes = array_map(static fn ($p) => $p->code, $roles->listPermissions());
        $roles->setPermissions($adminRole->id, $allPermissionCodes);
    }

    /**
     * Crea un tenant y lo deja listo para operar: alta + aprovisionamiento
     * en una sola transaccion.
     *
     * @param array<mixed>|null $settings
     */
    public static function createTenant(
        string $name,
        string $businessType = 'fast_food',
        string $currency = 'COP',
        ?array $settings = null,
    ): Tenant {
        if ($settings === null) {
            $settings = TenantSettings::defaultsFor($businessType);
        } else {
            try {
                TenantSettings::validate($settings);
            } catch (SettingsError $e) {
                throw new TenantProvisioningError($e->getMessage(), previous: $e);
            }
        }

        // Si quien llama ya abrio una transaccion (el script de alta, que
        // ademas crea la primera sucursal y el primer usuario), se respeta:
        // el alta completa tiene que ser todo o nada, no dejar a medias un
        // restaurante sin usuario con el cual entrar.
        $pdo = Database::admin();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $tenant = (new TenantRepository($pdo))->create($name, $businessType, $currency, $settings);
            self::provisionTenant($pdo, $tenant->id, $businessType);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $tenant;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
