<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\Request;
use App\Api\RawResponse;
use App\Api\RequestContext;
use App\Domain\Csv;
use App\Core\Money;
use App\Services\ReportError;
use App\Services\ReportService;

/**
 * Equivalente PHP de app/api/v1/reports.py.
 *
 * El branch_id es opcional y se valida contra el tenant del token: sin el,
 * el reporte es de toda la empresa. El tenant_id nunca se acepta del cliente.
 */
final class ReportController
{
    /** @return array{0: ?string, 1: ?string, 2: ?string} */
    private static function filters(RequestContext $ctx): array
    {
        return [Deps::optionalBranchId($ctx), Request::queryDate('from_date'), Request::queryDate('to_date')];
    }

    /** Los importes salen como cadena decimal, igual que serializa Pydantic un Decimal. */
    private static function money(mixed $value): string
    {
        return Money::toDecimalString(Money::fromDecimalString((string) ($value ?? '0')));
    }

    private static function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    public static function sales(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'reports.view');
        [$branchId, $from, $to] = self::filters($ctx);

        try {
            $data = ReportService::sales($ctx->tenantId, $branchId, $from, $to, Request::queryBool('compare'));
        } catch (ReportError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return [
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'totals' => [
                'orders' => (int) $data['totals']['orders'],
                'revenue' => self::money($data['totals']['revenue']),
                'avg_ticket' => self::money($data['totals']['avg_ticket']),
            ],
            'by_day' => array_map(static fn ($r) => [
                'day' => $r['day'],
                'orders' => (int) $r['orders'],
                'revenue' => self::money($r['revenue']),
            ], $data['by_day']),
            'by_channel' => array_map(static fn ($r) => [
                'channel' => $r['channel'],
                'orders' => (int) $r['orders'],
                'revenue' => self::money($r['revenue']),
            ], $data['by_channel']),
            'by_branch' => array_map(static fn ($r) => [
                'branch_id' => $r['branch_id'],
                'branch_name' => $r['branch_name'],
                'orders' => (int) $r['orders'],
                'revenue' => self::money($r['revenue']),
            ], $data['by_branch']),
            // Solo con ?compare=true: el mismo total del periodo anterior y
            // cuanto cambio. `change` viene en null cuando antes no habia
            // nada, porque no hay porcentaje que calcular contra cero.
            'previous' => $data['previous'] === null ? null : [
                'from_date' => $data['previous']['from_date'],
                'to_date' => $data['previous']['to_date'],
                'totals' => [
                    'orders' => (int) $data['previous']['totals']['orders'],
                    'revenue' => self::money($data['previous']['totals']['revenue']),
                    'avg_ticket' => self::money($data['previous']['totals']['avg_ticket']),
                ],
                'change' => $data['previous']['change'],
            ],
        ];
    }

    /**
     * Cualquier reporte como CSV.
     *
     * Se arma en el servidor y no en el navegador porque las listas de
     * ajustes que la pantalla muestra estan recortadas a 200 filas: un CSV
     * hecho con lo que hay en pantalla exportaria eso y nadie lo notaria.
     */
    public static function export(): RawResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'reports.view');
        [$branchId, $from, $to] = self::filters($ctx);

        try {
            [$encabezados, $filas, $nombre] = ReportService::export(
                $ctx->tenantId,
                $branchId,
                $from,
                $to,
                Request::queryString('report', 40) ?? 'sales',
            );
        } catch (ReportError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return RawResponse::csv(Csv::render($encabezados, $filas), $nombre);
    }

    public static function topProducts(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'reports.view');
        [$branchId, $from, $to] = self::filters($ctx);
        $limit = Request::queryInt('limit', default: 10, min: 1, max: 100);

        try {
            $rows = ReportService::topProducts($ctx->tenantId, $branchId, $from, $to, $limit);
        } catch (ReportError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return array_map(static fn ($r) => [
            'menu_item_id' => $r['menu_item_id'],
            'name' => $r['name'],
            'units' => (int) $r['units'],
            'revenue' => self::money($r['revenue']),
        ], $rows);
    }

    public static function prepTimes(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'reports.view');
        [$branchId, $from, $to] = self::filters($ctx);

        try {
            $data = ReportService::prepTimes($ctx->tenantId, $branchId, $from, $to);
        } catch (ReportError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return [
            'orders' => (int) $data['orders'],
            'avg_minutes' => self::float($data['avg_minutes']),
            'median_minutes' => self::float($data['median_minutes']),
            'min_minutes' => self::float($data['min_minutes']),
            'max_minutes' => self::float($data['max_minutes']),
        ];
    }

    /**
     * Ingresos por metodo de pago.
     *
     * Es el reporte que se mira al cerrar: sigue la plata y no el pedido, asi
     * que se fecha por el cobro y cuenta tambien lo cobrado sobre pedidos que
     * todavia no estan completados. Por eso su total no tiene por que
     * coincidir con el de ventas: miden cosas distintas.
     */
    public static function paymentMethods(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'reports.view');
        [$branchId, $from, $to] = self::filters($ctx);

        try {
            $data = ReportService::incomeByMethod($ctx->tenantId, $branchId, $from, $to);
        } catch (ReportError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return [
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'by_method' => array_map(static fn ($r) => [
                'method' => $r['method'],
                'charges' => (int) $r['charges'],
                'refunds' => (int) $r['refunds'],
                'charged' => self::money($r['charged']),
                'refunded' => self::money($r['refunded']),
                'net' => self::money($r['net']),
            ], $data['by_method']),
        ];
    }

    public static function salesByUser(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'reports.view');
        [$branchId, $from, $to] = self::filters($ctx);

        try {
            $rows = ReportService::salesByUser($ctx->tenantId, $branchId, $from, $to);
        } catch (ReportError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return array_map(static fn ($r) => [
            'user_id' => $r['user_id'],
            // Null cuando el usuario se dio de baja o el pedido entro por una
            // integracion: la pantalla decide como llamarlo, no la API.
            'user_name' => $r['user_name'],
            'orders' => (int) $r['orders'],
            'revenue' => self::money($r['revenue']),
        ], $rows);
    }

    /**
     * Lo que hubo que autorizar en el periodo.
     *
     * Cada lista viene con su total y su cuenta calculados sobre todo el
     * periodo, aunque las filas esten recortadas: un reporte que muestre 200
     * anulaciones y diga que suman solo esas 200 no sirve para cuadrar.
     */
    public static function adjustments(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'reports.view');
        [$branchId, $from, $to] = self::filters($ctx);

        try {
            $data = ReportService::adjustments($ctx->tenantId, $branchId, $from, $to);
        } catch (ReportError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return [
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'cancellations' => self::grupoAjuste($data['cancellations'], static fn ($r) => [
                'order_number' => $r['order_number'],
                'channel' => $r['channel'],
                'amount' => self::money($r['total']),
                'at' => $r['at'],
                'reason' => $r['reason'],
                'by_name' => $r['by_name'],
            ]),
            'refunds' => self::grupoAjuste($data['refunds'], static fn ($r) => [
                'order_number' => $r['order_number'],
                'method' => $r['method'],
                'amount' => self::money($r['amount']),
                'at' => $r['at'],
                'reason' => $r['reason'],
                'by_name' => $r['by_name'],
            ]),
            'discounts' => self::grupoAjuste($data['discounts'], static fn ($r) => [
                'order_number' => $r['order_number'],
                'amount' => self::money($r['discount']),
                'order_total' => self::money($r['total']),
                'at' => $r['at'],
                'reason' => null,
                'by_name' => $r['by_name'],
            ]),
        ];
    }

    /**
     * Empaqueta una lista de ajustes con su total.
     *
     * El total y la cuenta salen de las funciones de ventana de la consulta,
     * que corren antes del LIMIT: valen para todo el periodo aunque la lista
     * venga recortada.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param callable(array<string, mixed>): array<string, mixed> $mapear
     * @return array<string, mixed>
     */
    private static function grupoAjuste(array $rows, callable $mapear): array
    {
        $primera = $rows[0] ?? null;

        return [
            'count' => $primera === null ? 0 : (int) $primera['total_count'],
            'total' => self::money($primera['total_amount'] ?? '0'),
            'truncated' => $primera !== null && (int) $primera['total_count'] > count($rows),
            'items' => array_map($mapear, $rows),
        ];
    }

    public static function peakHours(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'reports.view');
        [$branchId, $from, $to] = self::filters($ctx);

        try {
            $rows = ReportService::peakHours($ctx->tenantId, $branchId, $from, $to);
        } catch (ReportError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return array_map(static fn ($r) => [
            'hour' => (int) $r['hour'],
            'orders' => (int) $r['orders'],
            'revenue' => self::money($r['revenue']),
        ], $rows);
    }
}
