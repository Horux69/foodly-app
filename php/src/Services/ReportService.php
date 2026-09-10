<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Money;
use App\Domain\PeriodComparison;
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
    public static function sales(
        string $tenantId,
        ?string $branchId,
        ?string $fromDate,
        ?string $toDate,
        bool $compare = false,
    ): array {
        [$start, $end] = self::resolveRange($fromDate, $toDate);
        self::checkBranch($tenantId, $branchId);

        $repo = new ReportRepository(Database::app());
        $totals = $repo->salesTotals($tenantId, $branchId, $start, $end);

        return [
            'from_date' => $start,
            'to_date' => $end,
            'totals' => $totals,
            'by_day' => $repo->salesByDay($tenantId, $branchId, $start, $end),
            'by_channel' => $repo->salesByChannel($tenantId, $branchId, $start, $end),
            'by_branch' => $repo->salesByBranch($tenantId, $branchId, $start, $end),
            'previous' => $compare ? self::previous($repo, $tenantId, $branchId, $start, $end, $totals) : null,
        ];
    }

    /**
     * El mismo total para el periodo anterior, y cuanto cambio.
     *
     * Un dueno no lee "vendi 4 millones", lee "vendi 12% mas que la semana
     * pasada". El periodo anterior tiene la misma cantidad de dias y termina
     * justo antes: comparar siete dias con treinta daria una caida que no
     * significa nada.
     *
     * @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private static function previous(
        ReportRepository $repo,
        string $tenantId,
        ?string $branchId,
        string $start,
        string $end,
        array $totals,
    ): array {
        [$antesInicio, $antesFin] = PeriodComparison::previousRange($start, $end);
        $antes = $repo->salesTotals($tenantId, $branchId, $antesInicio, $antesFin);

        // Los importes se comparan en centavos enteros: el porcentaje no es
        // dinero, pero de donde sale si.
        $centavos = static fn (mixed $v) => Money::fromDecimalString((string) ($v ?? '0'));

        return [
            'from_date' => $antesInicio,
            'to_date' => $antesFin,
            'totals' => $antes,
            'change' => [
                'orders' => PeriodComparison::change((int) $antes['orders'], (int) $totals['orders']),
                'revenue' => PeriodComparison::change($centavos($antes['revenue']), $centavos($totals['revenue'])),
                'avg_ticket' => PeriodComparison::change($centavos($antes['avg_ticket']), $centavos($totals['avg_ticket'])),
            ],
        ];
    }

    /**
     * Un reporte como filas para CSV.
     *
     * Se arma en el servidor y no en el navegador porque las listas de
     * ajustes que la pantalla muestra estan recortadas a 200 filas: un CSV
     * hecho con lo que hay en pantalla exportaria eso y nadie lo notaria.
     *
     * @return array{0: string[], 1: array<int, array<int, string|int|null>>, 2: string}
     *         encabezados, filas y nombre de archivo
     */
    public static function export(
        string $tenantId,
        ?string $branchId,
        ?string $fromDate,
        ?string $toDate,
        string $reporte,
    ): array {
        [$start, $end] = self::resolveRange($fromDate, $toDate);
        self::checkBranch($tenantId, $branchId);
        $repo = new ReportRepository(Database::app());

        $nombre = "{$reporte}-{$start}-a-{$end}.csv";

        return match ($reporte) {
            'sales' => [
                ['Dia', 'Pedidos', 'Venta'],
                array_map(
                    static fn (array $r) => [$r['day'], (int) $r['orders'], $r['revenue']],
                    $repo->salesByDay($tenantId, $branchId, $start, $end)
                ),
                $nombre,
            ],
            'top-products' => [
                ['Producto', 'Unidades', 'Venta'],
                array_map(
                    static fn (array $r) => [$r['name'], (int) $r['units'], $r['revenue']],
                    $repo->topProducts($tenantId, $branchId, $start, $end, 1000)
                ),
                $nombre,
            ],
            'payment-methods' => [
                ['Metodo', 'Movimientos', 'Total'],
                array_map(
                    static fn (array $r) => [$r['method'], (int) $r['payments'], $r['total']],
                    $repo->incomeByMethod($tenantId, $branchId, $start, $end)
                ),
                $nombre,
            ],
            'sales-by-user' => [
                ['Usuario', 'Pedidos', 'Venta'],
                array_map(
                    static fn (array $r) => [$r['name'], (int) $r['orders'], $r['revenue']],
                    $repo->salesByUser($tenantId, $branchId, $start, $end)
                ),
                $nombre,
            ],
            // Los tres tipos en un solo archivo y con una columna que los
            // distingue: para cuadrar un dia se miran juntos, no por
            // separado.
            'adjustments' => [
                ['Tipo', 'Pedido', 'Importe', 'Cuando', 'Motivo', 'Quien'],
                [
                    ...array_map(static fn (array $r) => [
                        'Anulacion', $r['order_number'], $r['total'], $r['at'], $r['reason'], $r['by_name'],
                    ], $repo->cancellations($tenantId, $branchId, $start, $end)),
                    ...array_map(static fn (array $r) => [
                        'Reembolso', $r['order_number'], $r['amount'], $r['at'], $r['reason'], $r['by_name'],
                    ], $repo->refunds($tenantId, $branchId, $start, $end)),
                    ...array_map(static fn (array $r) => [
                        'Descuento', $r['order_number'], $r['discount'], $r['at'], $r['reason'], $r['by_name'],
                    ], $repo->discounts($tenantId, $branchId, $start, $end)),
                ],
                $nombre,
            ],
            default => throw new ReportError(
                "Reporte desconocido: {$reporte}. Validos: sales, top-products, payment-methods, sales-by-user, adjustments"
            ),
        };
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
     * Ventas y propina por mesero (F4.4).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function salesByServer(
        string $tenantId,
        ?string $branchId,
        ?string $fromDate,
        ?string $toDate,
    ): array {
        [$start, $end] = self::resolveRange($fromDate, $toDate);
        self::checkBranch($tenantId, $branchId);
        return (new ReportRepository(Database::app()))->salesByServer($tenantId, $branchId, $start, $end);
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
