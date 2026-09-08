<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Consultas agregadas de reportes.
 *
 * Dos decisiones que atraviesan todas las consultas:
 *
 * - Se clasifica por order_statuses.category, nunca por code: cada
 *   restaurante nombra sus estados distinto pero la categoria es la parte
 *   normalizada (ver CLAUDE.md).
 * - Las fechas se resuelven en la zona horaria de cada sucursal
 *   (branches.timezone), no en UTC: un pedido de las 11pm en Bogota
 *   pertenece a ese dia, no al siguiente.
 *
 * El filtro por sucursal se arma condicionalmente en vez de repetir el
 * parametro dentro de un CAST(:branch_id AS uuid) IS NULL como en la version
 * Python: PDO con prepares nativos no admite el mismo parametro nombrado dos
 * veces, y de paso Postgres planifica mejor sin la condicion muerta.
 */
final class ReportRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Un pedido cuenta como venta cuando llego a un estado de categoria
     * 'completed'. Los cancelados no son venta y los abiertos todavia no lo son.
     */
    private static function soldFilter(?string $branchId): string
    {
        return '
            JOIN order_statuses s ON s.id = o.status_id
            JOIN branches b ON b.id = o.branch_id
            WHERE o.tenant_id = :tenant_id
              AND s.category = \'completed\'
              ' . ($branchId !== null ? 'AND o.branch_id = :branch_id' : '') . '
              AND (o.created_at AT TIME ZONE b.timezone)::date BETWEEN :from_date AND :to_date
        ';
    }

    /** @return array<string, mixed> */
    private static function params(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $params = ['tenant_id' => $tenantId, 'from_date' => $fromDate, 'to_date' => $toDate];
        if ($branchId !== null) {
            $params['branch_id'] = $branchId;
        }
        return $params;
    }

    /** @return array<string, mixed> */
    public function salesTotals(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT count(*) AS orders,
                    coalesce(sum(o.total), 0) AS revenue,
                    round(coalesce(avg(o.total), 0), 2) AS avg_ticket
             FROM orders o ' . self::soldFilter($branchId)
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetch();
    }

    /** @return array<int, array<string, mixed>> */
    public function salesByDay(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT (o.created_at AT TIME ZONE b.timezone)::date AS day,
                    count(*) AS orders,
                    sum(o.total) AS revenue
             FROM orders o ' . self::soldFilter($branchId) . '
             GROUP BY 1
             ORDER BY 1'
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function salesByChannel(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.channel, count(*) AS orders, sum(o.total) AS revenue
             FROM orders o ' . self::soldFilter($branchId) . '
             GROUP BY o.channel
             ORDER BY revenue DESC'
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function salesByBranch(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.id AS branch_id, b.name AS branch_name, count(*) AS orders, sum(o.total) AS revenue
             FROM orders o ' . self::soldFilter($branchId) . '
             GROUP BY b.id, b.name
             ORDER BY revenue DESC'
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
    }

    /**
     * Agrupa por menu_item_id y no por name_snapshot: si el producto se
     * renombro a mitad del periodo sus ventas deben sumar juntas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topProducts(
        string $tenantId,
        ?string $branchId,
        string $fromDate,
        string $toDate,
        int $limit,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT oi.menu_item_id,
                    mi.name,
                    sum(oi.quantity) AS units,
                    sum(oi.line_total) AS revenue
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN menu_items mi ON mi.id = oi.menu_item_id
             ' . self::soldFilter($branchId) . '
             GROUP BY oi.menu_item_id, mi.name
             ORDER BY units DESC
             LIMIT :limit'
        );

        foreach (self::params($tenantId, $branchId, $fromDate, $toDate) as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Minutos entre que el pedido entra a cocina y queda listo, leidos de
     * order_status_history. Incluye mediana porque el promedio se distorsiona
     * con un solo pedido olvidado.
     *
     * @return array<string, mixed>
     */
    public function prepTimes(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $branchCondition = $branchId !== null ? 'AND o.branch_id = :branch_id' : '';
        $stmt = $this->pdo->prepare(
            "WITH marks AS (
                SELECT h.order_id,
                       min(h.changed_at) FILTER (WHERE s.category = 'kitchen') AS started,
                       min(h.changed_at) FILTER (WHERE s.category = 'ready') AS ready
                FROM order_status_history h
                JOIN order_statuses s ON s.id = h.status_id
                JOIN orders o ON o.id = h.order_id
                JOIN branches b ON b.id = o.branch_id
                WHERE o.tenant_id = :tenant_id
                  {$branchCondition}
                  AND (o.created_at AT TIME ZONE b.timezone)::date BETWEEN :from_date AND :to_date
                GROUP BY h.order_id
            ), minutes AS (
                SELECT EXTRACT(EPOCH FROM (ready - started)) / 60 AS m
                FROM marks
                WHERE started IS NOT NULL AND ready IS NOT NULL
            )
            SELECT count(*) AS orders,
                   avg(m) AS avg_minutes,
                   percentile_cont(0.5) WITHIN GROUP (ORDER BY m) AS median_minutes,
                   min(m) AS min_minutes,
                   max(m) AS max_minutes
            FROM minutes"
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetch();
    }

    /**
     * Mide demanda, no venta: cuenta todo lo que no fue cancelado, incluidos
     * los pedidos aun abiertos. Por eso no reutiliza el filtro de ventas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function peakHours(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $branchCondition = $branchId !== null ? 'AND o.branch_id = :branch_id' : '';
        $stmt = $this->pdo->prepare(
            "SELECT EXTRACT(HOUR FROM (o.created_at AT TIME ZONE b.timezone))::int AS hour,
                    count(*) AS orders,
                    sum(o.total) AS revenue
             FROM orders o
             JOIN order_statuses s ON s.id = o.status_id
             JOIN branches b ON b.id = o.branch_id
             WHERE o.tenant_id = :tenant_id
               AND s.category <> 'cancelled'
               {$branchCondition}
               AND (o.created_at AT TIME ZONE b.timezone)::date BETWEEN :from_date AND :to_date
             GROUP BY 1
             ORDER BY 1"
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
    }
}
