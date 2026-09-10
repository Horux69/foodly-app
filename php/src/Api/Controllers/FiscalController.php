<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Core\Money;
use App\Domain\FiscalNumbering;
use App\Services\FiscalService;
use App\Services\FiscalServiceError;

/**
 * Documento fiscal de una venta (F12.1).
 *
 * Emitir pide `payments.register`: es el ultimo paso de cobrar, y quien
 * cobra tiene que poder entregar el papel. Las resoluciones las administra
 * quien configura el restaurante.
 */
final class FiscalController
{
    /** @param array<string, mixed> $doc */
    private static function documentoOut(array $doc): array
    {
        return [
            'id' => $doc['id'],
            'full_number' => $doc['full_number'],
            'status' => $doc['status'],
            'external_id' => $doc['external_id'],
            'provider' => $doc['provider'],
            'total' => Money::toDecimalString($doc['total']),
            'issued_at' => $doc['issued_at'],
            'transmitted_at' => $doc['transmitted_at'],
        ];
    }

    public static function show(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');

        try {
            $doc = FiscalService::forOrder($ctx->tenantId, $params['order_id']);
        } catch (FiscalServiceError $e) {
            throw new ApiException(404, $e->getMessage());
        }

        return ['document' => $doc === null ? null : self::documentoOut($doc)];
    }

    public static function emit(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'payments.register');

        try {
            [$doc, $yaExistia] = FiscalService::emit($ctx->tenantId, $params['order_id']);
        } catch (FiscalServiceError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        // 200 si ya estaba: volver a pedirlo devuelve el mismo documento, no
        // uno nuevo, igual que una llave de idempotencia.
        return new JsonResponse(self::documentoOut($doc), $yaExistia ? 200 : 201);
    }

    public static function resolutions(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.view');

        return array_map(static fn (array $r) => $r + [
            'remaining' => FiscalNumbering::restantes($r['range_to'], $r['current_number']),
            'running_out' => FiscalNumbering::porAcabarse($r['range_to'], $r['current_number']),
        ], FiscalService::resolutions($ctx->tenantId));
    }

    public static function createResolution(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'settings.edit');
        $body = Request::json();

        try {
            $resolucion = FiscalService::createResolution(
                $ctx->tenantId,
                Deps::activeBranchIdOrNull($ctx),
                Request::string($body, 'number', 1, 40),
                array_key_exists('prefix', $body) && $body['prefix'] !== null
                    ? Request::string($body, 'prefix', 0, 10)
                    : '',
                Request::int($body, 'range_from', min: 1),
                Request::int($body, 'range_to', min: 1),
                Request::optionalDate($body, 'valid_until'),
            );
        } catch (FiscalServiceError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse($resolucion, 201);
    }

    /** Reintenta lo que quedo sin transmitir. */
    public static function retry(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'payments.register');
        return FiscalService::retryPending($ctx->tenantId);
    }
}
