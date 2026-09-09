<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\BranchRepository;
use App\Repositories\ReportRepository;

final class ReportService
{
    private const DEFAULT_RANGE_DAYS = 30;

    /** @return array{0: string, 1: string} fechas YYYY-MM-DD */
    public static function resolveRange(?string $fromDate, ?string $toDate): array
    {
        $end = $toDate !== null
            ? new \DateTimeImmutable($toDate)
            : new \DateTimeImmutable('today');
        $start = $fromDate !== null
            ? new \DateTimeImmutable($fromDate)
            : $end->modify('-' . self::DEFAULT_RANGE_DAYS . ' days');

        if ($start > $end) {
            throw new ReportError('La fecha inicial no puede ser posterior a la final');
        }
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private static function checkBranch(string $tenantId, ?string $branchId): void
    {
        if ($branchId === null) {
            return;
        }
        if ((new BranchRepository(Database::app()))->get($tenantId, $branchId) === null) {
            throw new ReportError('La sucursal no existe para este tenant');
        }
    }

    /** @return array<string, mixed> */
    public static function sales(string $tenantId, ?string $branchId, ?string $fromDate, ?string $toDate): array
    {
        [$start, $end] = self::resolveRange($fromDate, $toDate);
        self::checkBranch($tenantId, $branchId);

        $repo = new ReportRepository(Database::app());
        return [
            'from_date' => $start,
            'to_date' => $end,
            'totals' => $repo->salesTotals($tenantId, $branchId, $start, $end),
            'by_day' => $repo->salesByDay($tenantId, $branchId, $start, $end),
            'by_channel' => $repo->salesByChannel($tenantId, $branchId, $start, $end),
            'by_branch' => $repo->salesByBranch($tenantId, $branchId, $start, $end),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function topProducts(
        string $tenantId,
        ?string $branchId,
        ?string $fromDate,
        ?string $toDate,
        int $limit,
    ): array {
        [$start, $end] = self::resolveRange($fromDate, $toDate);
        self::checkBranch($tenantId, $branchId);
        return (new ReportRepository(Database::app()))->topProducts($tenantId, $branchId, $start, $end, $limit);
    }

    /** @return array<string, mixed> */
    public static function prepTimes(string $tenantId, ?string $branchId, ?string $fromDate, ?string $toDate): array
    {
        [$start, $end] = self::resolveRange($fromDate, $toDate);
        self::checkBranch($tenantId, $branchId);
        return (new ReportRepository(Database::app()))->prepTimes($tenantId, $branchId, $start, $end);
    }

    /** @return array<string, mixed> */
    public static function incomeByMethod(
        string $tenantId,
        ?string $branchId,
        ?string $fromDate,
        ?string $toDate,
    ): array {
        [$start, $end] = self::resolveRange($fromDate, $toDate);
        self::checkBranch($tenantId, $branchId);

        return [
            'from_date' => $start,
            'to_date' => $end,
            'by_method' => (new ReportRepository(Database::app()))
                ->incomeByMethod($tenantId, $branchId, $start, $end),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function salesByUser(
        string $tenantId,
        ?string $branchId,
        ?string $fromDate,
        ?string $toDate,
    ): array {
        [$start, $end] = self::resolveRange($fromDate, $toDate);
        self::checkBranch($tenantId, $branchId);
        return (new ReportRepository(Database::app()))->salesByUser($tenantId, $branchId, $start, $end);
    }

    /**
     * Lo que hubo que autorizar: anulaciones, reembolsos y descuentos.
     *
     * Los tres se fechan por cuando ocurrio el ajuste y no por cuando se creo
     * el pedido, que es lo que se necesita para cuadrar un dia.
     *
     * @return array<string, mixed>
     */
    public static function adjustments(
        string $tenantId,
        ?string $branchId,
        ?string $fromDate,
        ?string $toDate,
    ): array {
        [$start, $end] = self::resolveRange($fromDate, $toDate);
        self::checkBranch($tenantId, $branchId);

        $repo = new ReportRepository(Database::app());
        return [
            'from_date' => $start,
            'to_date' => $end,
            'cancellations' => $repo->cancellations($tenantId, $branchId, $start, $end),
            'refunds' => $repo->refunds($tenantId, $branchId, $start, $end),
            'discounts' => $repo->discounts($tenantId, $branchId, $start, $end),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function peakHours(string $tenantId, ?string $branchId, ?string $fromDate, ?string $toDate): array
    {
        [$start, $end] = self::resolveRange($fromDate, $toDate);
        self::checkBranch($tenantId, $branchId);
        return (new ReportRepository(Database::app()))->peakHours($tenantId, $branchId, $start, $end);
    }
}
