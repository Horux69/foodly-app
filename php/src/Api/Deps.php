<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Database;
use App\Core\Security;

/**
 * Punto UNICO donde se resuelve el tenant. No replicar esta lectura de
 * token ni el SET del tenant en ningun controlador — equivalente PHP de
 * app/api/deps.py:get_context.
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

    public static function require(RequestContext $ctx, string $permission): RequestContext
    {
        if (!$ctx->has($permission)) {
            throw new ApiException(403, "Falta el permiso: {$permission}");
        }
        return $ctx;
    }
}
