<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Api\RequestContext;
use App\Core\Money;
use App\Domain\CashSessionTotals;
use App\Models\CashSession;
use App\Services\CashSessionError;
use App\Services\CashSessionService;
use App\Services\CashSessionView;

/**
 * Turnos de caja y arqueo.
 *
 * Los dos permisos no son el mismo a proposito:
 *
 *   * Abrir el turno pide `payments.register`. Es lo que hace un cajero al
 *     empezar su jornada y no deberia necesitar un permiso de supervision.
 *   * Ver el cuadre y cerrar piden `cash.close`. Asi, en un restaurante que
 *     separe los dos roles, quien cuenta el cajon no ve antes cuanto
 *     "deberia" haber — que es justamente el control que hace util un arqueo.
 */
final class CashController
{
    private static function sessionOut(CashSession $s): array
    {
        return [
            'id' => $s->id,
            'branch_id' => $s->branchId,
            'opening_float' => Money::toDecimalString($s->openingFloatCents),
            'counted_cash' => $s->countedCashCents === null
                ? null
                : Money::toDecimalString($s->countedCashCents),
            'note' => $s->note,
            'opened_at' => $s->openedAt,
            'closed_at' => $s->closedAt,
            'opened_by_name' => $s->openedByName,
            'closed_by_name' => $s->closedByName,
            'is_open' => $s->isOpen(),
        ];
    }

    private static function totalsOut(CashSessionTotals $t): array
    {
        return [
            'opening_float' => Money::toDecimalString($t->openingFloatCents),
            // Objeto siempre, tambien vacio: un array PHP sin claves sale
            // como [] y la pantalla recibiria dos formas distintas.
            'by_method' => (object) array_map(Money::toDecimalString(...), $t->netByMethod),
            'charged' => Money::toDecimalString($t->chargedCents),
            'refunded' => Money::toDecimalString($t->refundedCents),
            'net_collected' => Money::toDecimalString($t->netCollectedCents()),
            'expected_cash' => Money::toDecimalString($t->expectedCashCents),
            'counted_cash' => $t->countedCashCents === null
                ? null
                : Money::toDecimalString($t->countedCashCents),
            'difference' => $t->differenceCents === null
                ? null
                : Money::toDecimalString($t->differenceCents),
        ];
    }

    /** El cuadre solo para quien puede cerrar: ver el esperado antes de contar arruina el conteo. */
    private static function viewOut(RequestContext $ctx, CashSessionView $view): array
    {
        return [
            'session' => self::sessionOut($view->session),
            'totals' => $ctx->has('cash.close') ? self::totalsOut($view->totals) : null,
        ];
    }

    /** El turno abierto de la sucursal activa, o null si no hay ninguno. */
    public static function current(): array
    {
        $ctx = Deps::requireAny(Deps::getContext(), 'payments.register', 'cash.close');
        $session = CashSessionService::current($ctx->tenantId, Deps::activeBranchId($ctx));

        if ($session === null) {
            return ['session' => null, 'totals' => null];
        }
        return self::viewOut($ctx, CashSessionService::view($session));
    }

    public static function open(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'payments.register');
        $body = Request::json();

        try {
            $view = CashSessionService::open(
                $ctx->tenantId,
                Deps::activeBranchId($ctx),
                $ctx->userId,
                Money::fromDecimalString(Request::decimalString($body, 'opening_float', '0')),
            );
        } catch (CashSessionError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::viewOut($ctx, $view), 201);
    }

    public static function close(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'cash.close');
        $body = Request::json();

        try {
            $view = CashSessionService::close(
                $ctx->tenantId,
                $params['session_id'],
                $ctx->userId,
                Money::fromDecimalString(Request::decimalString($body, 'counted_cash')),
                Request::optionalString($body, 'note'),
            );
        } catch (CashSessionError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return self::viewOut($ctx, $view);
    }

    /** Los ultimos turnos de la sucursal activa, con su cuadre. */
    public static function history(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'cash.close');
        $views = CashSessionService::history(
            $ctx->tenantId,
            Deps::activeBranchId($ctx),
            Request::queryInt('limit', default: 30, min: 1, max: 100),
        );

        return array_map(static fn (CashSessionView $v) => self::viewOut($ctx, $v), $views);
    }
}
