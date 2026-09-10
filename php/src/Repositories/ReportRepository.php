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
     * Cuantas filas devuelve como maximo cada lista de ajustes.
     *
     * Los totales que las acompañan se calculan con funciones de ventana, que
     * corren antes del LIMIT: la lista se corta pero la suma sigue siendo la
     * de todo el periodo. Un reporte que muestre 200 anulaciones y diga que
     * suman solo esas 200 no sirve para cuadrar.
     */
    private const MAX_AJUSTES = 200;

    /**
     * Ingresos por metodo de pago.
     *
     * A diferencia de los reportes de venta, este sigue la plata y no el
     * pedido: se agrupa por la fecha del cobro y no la del pedido, y no se
     * exige que el pedido este completado. Un cobro de hoy sobre un pedido de
     * ayer entro hoy en la caja, y uno sobre un pedido todavia abierto es
     * plata que ya se recibio.
     *
     * Un cobro cuenta aunque este marcado 'refunded' —esa etiqueta solo dice
     * que ya se revirtio— y quien resta es su fila de reembolso.
     *
     * @return array<int, array<string, mixed>>
     */
    public function incomeByMethod(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $branchCondition = $branchId !== null ? 'AND o.branch_id = :branch_id' : '';
        $stmt = $this->pdo->prepare(
            "SELECT p.method,
                    count(*) FILTER (
                        WHERE p.refund_of_payment_id IS NULL AND p.status IN ('paid', 'refunded')
                    ) AS charges,
                    count(*) FILTER (
                        WHERE p.refund_of_payment_id IS NOT NULL AND p.status = 'paid'
                    ) AS refunds,
                    coalesce(sum(p.amount) FILTER (
                        WHERE p.refund_of_payment_id IS NULL AND p.status IN ('paid', 'refunded')
                    ), 0) AS charged,
                    coalesce(sum(p.amount) FILTER (
                        WHERE p.refund_of_payment_id IS NOT NULL AND p.status = 'paid'
                    ), 0) AS refunded,
                    coalesce(sum(p.amount) FILTER (
                        WHERE p.refund_of_payment_id IS NULL AND p.status IN ('paid', 'refunded')
                    ), 0) - coalesce(sum(p.amount) FILTER (
                        WHERE p.refund_of_payment_id IS NOT NULL AND p.status = 'paid'
                    ), 0) AS net
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             JOIN branches b ON b.id = o.branch_id
             WHERE o.tenant_id = :tenant_id
               {$branchCondition}
               AND (p.created_at AT TIME ZONE b.timezone)::date BETWEEN :from_date AND :to_date
             GROUP BY p.method
             ORDER BY net DESC"
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
    }

    /**
     * Ventas por quien tomo el pedido.
     *
     * Usa el mismo filtro que el resto de los reportes de venta: solo cuenta
     * lo completado. `created_by` puede ser nulo —el usuario se dio de baja, o
     * el pedido entro por una integracion— y esas ventas se agrupan juntas en
     * vez de desaparecer del total.
     *
     * @return array<int, array<string, mixed>>
     */
    public function salesByUser(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.created_by AS user_id,
                    u.name AS user_name,
                    count(*) AS orders,
                    sum(o.total) AS revenue
             FROM orders o
             LEFT JOIN users u ON u.id = o.created_by
             ' . self::soldFilter($branchId) . '
             GROUP BY o.created_by, u.name
             ORDER BY revenue DESC'
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
    }

    /**
     * Ventas por canal, con lo que se queda cada plataforma (F9.4).
     *
     * La comision se suma pedido a pedido con el porcentaje congelado en
     * cada uno, redondeando igual que `Domain\SourceCommission`: sumar
     * primero y aplicar el porcentaje al final daria una cifra distinta en
     * cuanto haya dos canales con tarifas distintas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function salesBySource(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.sales_source_id AS source_id,
                    c.name AS source_name,
                    count(*) AS orders,
                    sum(o.total) AS revenue,
                    coalesce(sum(round(o.total * coalesce(o.commission_percent, 0) / 100, 2)), 0) AS commission
             FROM orders o
             LEFT JOIN sales_sources c ON c.id = o.sales_source_id
             ' . self::soldFilter($branchId) . '
             GROUP BY o.sales_source_id, c.name
             ORDER BY revenue DESC'
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
    }

    /**
     * Cumplimiento de la promesa de entrega (F9.5).
     *
     * Se fecha por la entrega y no por la creacion del pedido: al mirar el
     * dia importa lo que se entrego hoy, aunque el pedido fuera de anoche.
     * Solo cuentan los que ya llegaron —de los que siguen en la calle
     * todavia no se sabe— y el porcentaje se mide contra los que tenian
     * promesa: un pedido sin zona no prometio nada y contarlo como
     * incumplido seria mentir al reves.
     *
     * @return array<string, mixed>
     */
    public function deliveryPromise(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $branchCondition = $branchId !== null ? 'AND o.branch_id = :branch_id' : '';
        $stmt = $this->pdo->prepare(
            "SELECT count(*) AS delivered,
                    count(*) FILTER (WHERE d.estimated_time IS NOT NULL) AS promised,
                    count(*) FILTER (
                        WHERE d.estimated_time IS NOT NULL AND d.delivered_at <= d.estimated_time
                    ) AS on_time,
                    round(avg(
                        EXTRACT(EPOCH FROM (d.delivered_at - o.created_at)) / 60
                    )::numeric, 1) AS avg_minutes,
                    round(avg(
                        EXTRACT(EPOCH FROM (d.delivered_at - d.estimated_time)) / 60
                    ) FILTER (
                        WHERE d.estimated_time IS NOT NULL AND d.delivered_at > d.estimated_time
                    )::numeric, 1) AS avg_delay
               FROM delivery_info d
               JOIN orders o ON o.id = d.order_id
               JOIN branches b ON b.id = o.branch_id
              WHERE o.tenant_id = :tenant_id
                {$branchCondition}
                AND d.delivered_at IS NOT NULL
                AND (d.delivered_at AT TIME ZONE b.timezone)::date BETWEEN :from_date AND :to_date"
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetch();
    }

    /**
     * Ventas y propina por mesero.
     *
     * No es lo mismo que salesByUser: aquel agrupa por quien digito el
     * pedido y este por quien atendio la mesa, que en un restaurante de
     * servicio es otra persona (F4.4). Cuando nadie asigno mesero se cae a
     * `created_by`, que es tambien el valor por defecto al crear: asi las
     * ventas viejas —las de antes de que la columna existiera— no aparecen
     * todas juntas en un cajon "sin mesero" que no significa nada.
     *
     * La venta va sin la propina y la propina aparte. Sumarlas en una sola
     * cifra diria que un mesero vendio mas por haber recibido mas propina,
     * que es justo lo contrario de lo que el reporte quiere mostrar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function salesByServer(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT coalesce(o.server_id, o.created_by) AS user_id,
                    u.name AS user_name,
                    count(*) AS orders,
                    sum(o.total - o.tip) AS revenue,
                    sum(o.tip) AS tips
             FROM orders o
             LEFT JOIN users u ON u.id = coalesce(o.server_id, o.created_by)
             ' . self::soldFilter($branchId) . '
             GROUP BY coalesce(o.server_id, o.created_by), u.name
             ORDER BY revenue DESC'
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
    }

    /**
     * Pedidos anulados, con quien los anulo y por que.
     *
     * Se fecha por cuando se cancelo y no por cuando se creo el pedido: al
     * cuadrar el dia importa lo que se anulo hoy, aunque el pedido fuera de
     * ayer. El LATERAL busca la ultima entrada de la bitacora que lo llevo a
     * un estado de categoria 'cancelled'; el motivo sale de ahi
     * (order_status_history.note).
     *
     * @return array<int, array<string, mixed>>
     */
    public function cancellations(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $branchCondition = $branchId !== null ? 'AND o.branch_id = :branch_id' : '';
        $stmt = $this->pdo->prepare(
            "SELECT o.order_number,
                    o.total,
                    o.channel,
                    h.changed_at AS at,
                    h.note AS reason,
                    u.name AS by_name,
                    count(*) OVER () AS total_count,
                    sum(o.total) OVER () AS total_amount
             FROM orders o
             JOIN order_statuses s ON s.id = o.status_id
             JOIN branches b ON b.id = o.branch_id
             LEFT JOIN LATERAL (
                 SELECT hh.changed_at, hh.note, hh.changed_by
                 FROM order_status_history hh
                 JOIN order_statuses hs ON hs.id = hh.status_id
                 WHERE hh.order_id = o.id AND hs.category = 'cancelled'
                 ORDER BY hh.changed_at DESC
                 LIMIT 1
             ) h ON true
             LEFT JOIN users u ON u.id = h.changed_by
             WHERE o.tenant_id = :tenant_id
               AND s.category = 'cancelled'
               {$branchCondition}
               AND (coalesce(h.changed_at, o.created_at) AT TIME ZONE b.timezone)::date
                   BETWEEN :from_date AND :to_date
             ORDER BY h.changed_at DESC NULLS LAST
             LIMIT " . self::MAX_AJUSTES
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
    }

    /**
     * Reembolsos del periodo, con quien los hizo y por que.
     *
     * Fechados por cuando salio la plata, no por cuando entro.
     *
     * @return array<int, array<string, mixed>>
     */
    public function refunds(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $branchCondition = $branchId !== null ? 'AND o.branch_id = :branch_id' : '';
        $stmt = $this->pdo->prepare(
            "SELECT o.order_number,
                    p.method,
                    p.amount,
                    p.created_at AS at,
                    p.note AS reason,
                    u.name AS by_name,
                    count(*) OVER () AS total_count,
                    sum(p.amount) OVER () AS total_amount
             FROM payments p
             JOIN orders o ON o.id = p.order_id
             JOIN branches b ON b.id = o.branch_id
             LEFT JOIN users u ON u.id = p.created_by
             WHERE o.tenant_id = :tenant_id
               AND p.refund_of_payment_id IS NOT NULL
               AND p.status = 'paid'
               {$branchCondition}
               AND (p.created_at AT TIME ZONE b.timezone)::date BETWEEN :from_date AND :to_date
             ORDER BY p.created_at DESC
             LIMIT " . self::MAX_AJUSTES
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
    }

    /**
     * Descuentos aplicados, con quien tomo el pedido.
     *
     * Se excluyen los cancelados: un descuento sobre un pedido que no se
     * vendio no es plata que el restaurante haya dejado de cobrar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function discounts(string $tenantId, ?string $branchId, string $fromDate, string $toDate): array
    {
        $branchCondition = $branchId !== null ? 'AND o.branch_id = :branch_id' : '';
        $stmt = $this->pdo->prepare(
            "SELECT o.order_number,
                    o.discount,
                    o.total,
                    o.created_at AS at,
                    dr.name AS reason,
                    -- Quien lo autorizo, y si no consta, quien tomo el pedido.
                    coalesce(aut.name, u.name) AS by_name,
                    count(*) OVER () AS total_count,
                    sum(o.discount) OVER () AS total_amount
             FROM orders o
             JOIN order_statuses s ON s.id = o.status_id
             JOIN branches b ON b.id = o.branch_id
             LEFT JOIN users u ON u.id = o.created_by
             LEFT JOIN users aut ON aut.id = o.discount_by
             LEFT JOIN discount_reasons dr ON dr.id = o.discount_reason_id
             WHERE o.tenant_id = :tenant_id
               AND o.discount > 0
               AND s.category <> 'cancelled'
               {$branchCondition}
               AND (o.created_at AT TIME ZONE b.timezone)::date BETWEEN :from_date AND :to_date
             ORDER BY o.discount DESC
             LIMIT " . self::MAX_AJUSTES
        );
        $stmt->execute(self::params($tenantId, $branchId, $fromDate, $toDate));
        return $stmt->fetchAll();
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
