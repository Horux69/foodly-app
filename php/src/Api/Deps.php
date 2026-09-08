<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Database;
use App\Core\Security;
use App\Repositories\BranchRepository;

/**
 * Punto UNICO donde se resuelve el tenant. No replicar esta lectura de
 * token ni el SET del tenant en ningun controlador — equivalente PHP de
 * app/api/deps.py:get_context.
 *
 * Y, por la misma razon, el punto unico donde se resuelve la sucursal
 * activa de la peticion (activeBranchId).
 */
final class Deps
{
    public static function getContext(): RequestContext
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            throw new ApiException(401, 'Falta el token de autenticacion');
        }
        $token = substr($header, 7);

        try {
            $payload = Security::decodeAccessToken($token);
        } catch (\Throwable) {
            throw new ApiException(401, 'Token invalido');
        }

        $ctx = new RequestContext(
            userId: (string) $payload['sub'],
            tenantId: (string) $payload['tenant_id'],
            branchId: isset($payload['branch_id']) ? (string) $payload['branch_id'] : null,
            roleCode: (string) ($payload['role'] ?? ''),
            permissions: (array) ($payload['permissions'] ?? []),
        );

        // Red de seguridad: aunque una consulta olvide el WHERE tenant_id,
        // las politicas RLS de Postgres impiden ver datos de otra empresa.
        Database::setTenantContext(Database::app(), $ctx->tenantId);

        return $ctx;
    }

    /**
     * Sucursal sobre la que opera esta peticion.
     *
     * Punto UNICO donde se resuelve, igual que getContext lo es para el
     * tenant: los controladores no vuelven a leer branch_id del cliente.
     *
     * A diferencia del tenant, el branch_id SI puede venir del cliente —hace
     * falta para el selector de sucursal de un dueno multi-sede—, pero solo
     * como parametro de query y siempre validado contra el tenant del token.
     * La consulta filtra por tenant_id y ademas corre bajo RLS, asi que una
     * sucursal de otra empresa simplemente no existe desde aqui.
     *
     * Sin parametro se cae a la sucursal asignada al usuario en el token.
     */
    public static function activeBranchId(RequestContext $ctx): string
    {
        $branchId = self::activeBranchIdOrNull($ctx);
        if ($branchId === null) {
            throw new ApiException(400, 'Elige una sucursal para operar');
        }
        return $branchId;
    }

    /**
     * Igual que activeBranchId pero sin exigir una, para lo que sabe
     * funcionar sin sucursal: el menu, cuyos precios por sucursal son un
     * override sobre el precio base.
     */
    public static function activeBranchIdOrNull(RequestContext $ctx): ?string
    {
        return self::optionalBranchId($ctx) ?? $ctx->branchId;
    }

    /**
     * Solo la sucursal que pidio el cliente, ya validada, sin caer en la del
     * token: para los reportes, donde "ninguna" significa toda la empresa.
     */
    public static function optionalBranchId(RequestContext $ctx): ?string
    {
        $requested = Request::queryUuid('branch_id');
        if ($requested === null) {
            return null;
        }
        $branch = (new BranchRepository(Database::app()))->get($ctx->tenantId, $requested);
        if ($branch === null) {
            throw new ApiException(404, 'La sucursal no existe en esta empresa');
        }
        return $branch->id;
    }

    public static function require(RequestContext $ctx, string $permission): RequestContext
    {
        if (!$ctx->has($permission)) {
            throw new ApiException(403, "Falta el permiso: {$permission}");
        }
        return $ctx;
    }

    /**
     * Basta con uno de los permisos.
     *
     * Para los datos que son de configuracion y de operacion a la vez: las
     * zonas de reparto las administra quien ve la configuracion, pero
     * tambien las necesita el cajero que esta tomando un domicilio y tiene
     * que elegir una. Exigir 'settings.view' ahi obligaria a darle a la caja
     * un permiso de administracion para poder vender.
     */
    public static function requireAny(RequestContext $ctx, string ...$permissions): RequestContext
    {
        foreach ($permissions as $permission) {
            if ($ctx->has($permission)) {
                return $ctx;
            }
        }
        throw new ApiException(403, 'Falta alguno de los permisos: ' . implode(', ', $permissions));
    }
}
