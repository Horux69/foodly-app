<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Core\Permissions;
use App\Domain\OrderStatus;
use App\Models\OrderStatusRow;
use App\Services\AdminError;
use App\Services\StatusConfigService;

/**
 * Estados de pedido y transiciones desde la web (F5.2).
 *
 * Antes solo llegaban por las semillas de TenantProvisioning: ningun
 * restaurante podia renombrar "Preparacion" ni decidir quien avanza que.
 */
final class OrderStatusController
{
    private static function statusOut(OrderStatusRow $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'category' => $s->category,
            'color' => $s->color,
            'sort_order' => $s->sortOrder,
            'is_initial' => $s->isInitial,
            'is_final' => $s->isFinal,
        ];
    }

    public static function getConfiguration(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.view');
        [$statuses, $transitions, $problems, $warnings] = StatusConfigService::getConfiguration($ctx->tenantId);

        return [
            'statuses' => array_map(self::statusOut(...), $statuses),
            'transitions' => $transitions,
            // Lo que impide operar. Normalmente vacio: solo se llega aqui si
            // algo entro por fuera de la API, y hay que verlo para arreglarlo.
            'problems' => $problems,
            // Lo que no impide operar pero casi seguro esta mal: sin estado de
            // cierre, sin anulacion, salidas en un estado final, o un estado
            // al que no se llega.
            'warnings' => $warnings,
            // El vocabulario fijo de la plataforma y el catalogo de permisos,
            // para que la pantalla no los tenga escritos a mano. El permiso va
            // con su descripcion, que es lo que se lee al elegir quien puede
            // hacer una transicion.
            'categories' => OrderStatus::CATEGORIES,
            'permissions' => array_map(
                static fn (string $code, string $description) => ['code' => $code, 'description' => $description],
                array_keys(Permissions::CATALOG),
                array_values(Permissions::CATALOG),
            ),
        ];
    }

    /** @return array{0: string, 1: ?string, 2: int, 3: bool} categoria, color, orden, es final */
    private static function shape(array $body): array
    {
        return [
            Request::string($body, 'category', 1, 20),
            Request::optionalString($body, 'color'),
            array_key_exists('sort_order', $body) ? Request::int($body, 'sort_order') : 0,
            array_key_exists('is_final', $body) && Request::bool($body, 'is_final'),
        ];
    }

    public static function createStatus(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        $body = Request::json();
        [$category, $color, $sortOrder, $isFinal] = self::shape($body);

        try {
            $status = StatusConfigService::createStatus(
                $ctx->tenantId,
                Request::string($body, 'name', 1, 80),
                $category,
                $color,
                $sortOrder,
                $isFinal,
            );
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::statusOut($status), 201);
    }

    public static function updateStatus(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        $body = Request::json();
        [$category, $color, $sortOrder, $isFinal] = self::shape($body);

        try {
            $status = StatusConfigService::updateStatus(
                $ctx->tenantId,
                $params['status_id'],
                Request::string($body, 'name', 1, 80),
                $category,
                $color,
                $sortOrder,
                $isFinal,
            );
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return self::statusOut($status);
    }

    public static function setInitial(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        try {
            return self::statusOut(StatusConfigService::setInitial($ctx->tenantId, $params['status_id']));
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }
    }

    public static function deleteStatus(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        try {
            StatusConfigService::deleteStatus($ctx->tenantId, $params['status_id']);
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }
        return new JsonResponse(null, 204);
    }

    /**
     * Reemplaza las salidas de un estado. Se manda la lista entera y no una
     * transicion suelta para que la comprobacion de "nadie se queda sin
     * salida" corra sobre el resultado.
     */
    public static function setTransitions(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        $body = Request::json();

        $crudas = $body['transitions'] ?? null;
        if (!is_array($crudas)) {
            throw new ApiException(422, "'transitions' debe ser una lista");
        }

        $targets = [];
        foreach ($crudas as $cruda) {
            if (!is_array($cruda)) {
                throw new ApiException(422, "'transitions' debe ser una lista de objetos");
            }
            $targets[] = [
                'to_status_id' => Request::uuid($cruda, 'to_status_id'),
                'required_permission' => Request::optionalString($cruda, 'required_permission'),
            ];
        }

        try {
            StatusConfigService::setTransitions($ctx->tenantId, $params['status_id'], $targets);
        } catch (AdminError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        [$statuses, $transitions, $problems, $warnings] = StatusConfigService::getConfiguration($ctx->tenantId);
        return [
            'statuses' => array_map(self::statusOut(...), $statuses),
            'transitions' => $transitions,
            'problems' => $problems,
            'warnings' => $warnings,
        ];
    }
}
