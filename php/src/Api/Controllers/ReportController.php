<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\Request;
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
    private static function filters(): array
    {
        return [Request::queryUuid('branch_id'), Request::queryDate('from_date'), Request::queryDate('to_date')];
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
        [$branchId, $from, $to] = self::filters();

        try {
            $data = ReportService::sales($ctx->tenantId, $branchId, $from, $to);
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
        ];
    }

    public static function topProducts(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'reports.view');
        [$branchId, $from, $to] = self::filters();
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
        [$branchId, $from, $to] = self::filters();

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

    public static function peakHours(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'reports.view');
        [$branchId, $from, $to] = self::filters();

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
