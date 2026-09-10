<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Models\SalesSource;
use App\Services\SalesSourceError;
use App\Services\SalesSourceService;

/** Origenes de venta y su comision (F9.4). */
final class SalesSourceController
{
    private static function out(SalesSource $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'commission_percent' => $c->commissionPercent,
            'is_active' => $c->isActive,
        ];
    }

    /**
     * La lista.
     *
     * Con 'orders.create' tambien: quien toma un pedido necesita decir de
     * donde vino, y para eso no deberia hacer falta un permiso de
     * administracion. Solo los encendidos, salvo para quien configura.
     */
    public static function index(): array
    {
        $ctx = Deps::requireAny(Deps::getContext(), 'orders.create', 'settings.view');
        return array_map(
            self::out(...),
            SalesSourceService::listChannels($ctx->tenantId, soloActivos: !$ctx->has('settings.view')),
        );
    }

    public static function create(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        $body = Request::json();

        try {
            $origen = SalesSourceService::create(
                $ctx->tenantId,
                Request::string($body, 'name', 1, 60),
                (float) Request::decimalString($body, 'commission_percent', '0'),
            );
        } catch (SalesSourceError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::out($origen), 201);
    }

    public static function update(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        $body = Request::json();

        try {
            $origen = SalesSourceService::update(
                $ctx->tenantId,
                $params['source_id'],
                Request::string($body, 'name', 1, 60),
                (float) Request::decimalString($body, 'commission_percent', '0'),
                Request::bool($body, 'is_active'),
            );
        } catch (SalesSourceError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return self::out($origen);
    }

    public static function delete(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');

        try {
            SalesSourceService::delete($ctx->tenantId, $params['source_id']);
        } catch (SalesSourceError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(null, 204);
    }
}
