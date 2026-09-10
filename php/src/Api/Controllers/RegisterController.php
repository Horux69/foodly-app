<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Models\Register;
use App\Services\RegisterError;
use App\Services\RegisterService;

/**
 * Cajas de una sucursal (F7.4).
 *
 * Existen solo si el restaurante tiene mas de un punto de cobro; con una
 * sola caja —el caso de casi todos— la sucursal sigue teniendo un turno
 * unico sin que nadie configure nada.
 */
final class RegisterController
{
    private static function out(Register $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'is_active' => $r->isActive];
    }

    /**
     * La lista, para administrar o para elegir en cual se esta cobrando.
     *
     * Con 'settings.view' se ven tambien las apagadas —es quien configura
     * quien necesita reactivar una—; con solo el permiso operativo, apenas
     * las activas: es lo que le sirve al cajero para elegir donde esta.
     */
    public static function index(array $params): array
    {
        $ctx = Deps::requireAny(Deps::getContext(), 'settings.view', 'payments.register', 'cash.close');
        return array_map(
            self::out(...),
            RegisterService::listForBranch($ctx->tenantId, $params['branch_id'], soloActivas: !$ctx->has('settings.view')),
        );
    }

    public static function create(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $body = Request::json();

        try {
            $registro = RegisterService::create($ctx->tenantId, $params['branch_id'], Request::string($body, 'name', 1, 60));
        } catch (RegisterError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::out($registro), 201);
    }

    public static function update(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');
        $body = Request::json();

        try {
            $registro = RegisterService::update(
                $ctx->tenantId,
                $params['register_id'],
                Request::string($body, 'name', 1, 60),
                Request::bool($body, 'is_active'),
            );
        } catch (RegisterError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return self::out($registro);
    }

    public static function delete(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'branches.manage');

        try {
            RegisterService::delete($ctx->tenantId, $params['register_id']);
        } catch (RegisterError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(null, 204);
    }
}
