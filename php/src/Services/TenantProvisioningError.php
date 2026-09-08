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
