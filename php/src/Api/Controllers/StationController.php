<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Services\StationError;
use App\Services\StationService;

/**
 * Estaciones de preparacion (F8.1).
 *
 * Verlas pide `menu.view` porque el ruteo es parte de como esta armada la
 * carta —y quien la edita necesita saber a donde va cada categoria—;
 * tocarlas pide `menu.edit`, el mismo permiso que crear un producto.
 */
final class StationController
{
    public static function index(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.view');
        return StationService::list($ctx->tenantId);
    }

    public static function create(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();

        try {
            $estacion = StationService::create(
                $ctx->tenantId,
                Request::string($body, 'name', 1, StationService::NOMBRE_MAX),
                array_key_exists('sort_order', $body) ? Request::int($body, 'sort_order') : 0,
            );
        } catch (StationError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse($estacion, 201);
    }

    public static function update(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();

        try {
            StationService::update(
                $ctx->tenantId,
                $params['station_id'],
                Request::string($body, 'name', 1, StationService::NOMBRE_MAX),
                array_key_exists('is_active', $body) ? Request::bool($body, 'is_active') : true,
            );
        } catch (StationError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return ['id' => $params['station_id']];
    }

    public static function delete(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');

        try {
            StationService::delete($ctx->tenantId, $params['station_id']);
        } catch (StationError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(null, 204);
    }

    /** A que estacion manda una categoria. `station_id` en null la devuelve a la general. */
    public static function assignCategory(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();

        try {
            StationService::assignCategory(
                $ctx->tenantId,
                $params['category_id'],
                Request::optionalUuid($body, 'station_id'),
            );
        } catch (StationError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return ['category_id' => $params['category_id']];
    }
}
