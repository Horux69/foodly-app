<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Money;
use PDO;

/**
 * Lo que cada repartidor debe traer, y los cuadres ya hechos.
 *
 * El efectivo pendiente sale de los cobros reales de sus pedidos, no de una
 * columna que alguien mantenga: es la misma decision del arqueo, y la que
 * hace que un reembolso registrado despues se refleje solo.
 *
 * La ventana la marca el ultimo cuadre de ese repartidor. Sin marcar pago
 * por pago: el siguiente cuadre arranca donde termino el anterior, que es lo
 * que impide contar dos veces el mismo cobro.
 */
final class CourierSettlementRepository
{
    /**
     * Los cobros en efectivo cuentan como en los reportes: el cobro original
     * suma aunque este marcado 'refunded' —esa etiqueta dice que ya se
     * revirtio, y quien lo revierte es su fila de reembolso—.
     */
    private const EFECTIVO = "p.method = 'cash'";

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Lo pendiente de cada repartidor con cobros sin cuadrar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(string $tenantId, string $branchId): array
    {
        $stmt = $this->pdo->prepare(
            "WITH ultimo AS (
                SELECT courier_id, max(to_at) AS desde
                  FROM courier_settlements
                 WHERE tenant_id = :tenant_id
                 GROUP BY courier_id
             )
             SELECT d.courier_id,
                    u.name AS courier_name,
                    ultimo.desde AS from_at,
                    count(DISTINCT o.id) AS orders,
                    coalesce(sum(p.amount) FILTER (
                        WHERE p.refund_of_payment_id IS NULL AND p.status IN ('paid', 'refunded')
                    ), 0) AS charged,
                    coalesce(sum(p.amount) FILTER (
                        WHERE p.refund_of_payment_id IS NOT NULL AND p.status = 'paid'
                    ), 0) AS refunded
               FROM delivery_info d
               JOIN orders o ON o.id = d.order_id
               JOIN payments p ON p.order_id = o.id AND " . self::EFECTIVO . "
               LEFT JOIN ultimo ON ultimo.courier_id = d.courier_id
               LEFT JOIN users u ON u.id = d.courier_id
              WHERE o.tenant_id = :tenant_id
                AND o.branch_id = :branch_id
                AND d.courier_id IS NOT NULL
                AND (ultimo.desde IS NULL OR p.created_at > ultimo.desde)
              GROUP BY d.courier_id, u.name, ultimo.desde
              ORDER BY u.name"
        );
        $stmt->execute(['tenant_id' => $tenantId, 'branch_id' => $branchId]);
        return $stmt->fetchAll();
    }

    /**
     * Los pedidos que entran en el cuadre de un repartidor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function orders(string $tenantId, string $branchId, string $courierId, ?string $desde): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.order_number,
                    o.total,
                    d.delivered_at,
                    coalesce(sum(p.amount) FILTER (
                        WHERE p.refund_of_payment_id IS NULL AND p.status IN ('paid', 'refunded')
                    ), 0) - coalesce(sum(p.amount) FILTER (
                        WHERE p.refund_of_payment_id IS NOT NULL AND p.status = 'paid'
                    ), 0) AS cash
               FROM delivery_info d
               JOIN orders o ON o.id = d.order_id
               JOIN payments p ON p.order_id = o.id AND " . self::EFECTIVO . "
              WHERE o.tenant_id = :tenant_id
                AND o.branch_id = :branch_id
                AND d.courier_id = :courier_id
                AND (:desde::timestamptz IS NULL OR p.created_at > :desde_cmp::timestamptz)
              GROUP BY o.id, o.order_number, o.total, d.delivered_at
              ORDER BY d.delivered_at NULLS LAST, o.order_number"
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'courier_id' => $courierId,
            // Dos veces el mismo valor y no el mismo parametro: sin
            // emulacion, PDO no deja repetir un nombre en la consulta.
            'desde' => $desde,
            'desde_cmp' => $desde,
        ]);
        return $stmt->fetchAll();
    }

    /** Desde cuando cuenta lo pendiente de este repartidor: su ultimo cuadre. */
    public function lastClosedAt(string $tenantId, string $courierId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT max(to_at) FROM courier_settlements WHERE tenant_id = :tenant_id AND courier_id = :courier_id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'courier_id' => $courierId]);
        $valor = $stmt->fetchColumn();
        return $valor === false || $valor === null ? null : (string) $valor;
    }

    /** @return array<string, mixed> la fila creada */
    public function create(
        string $tenantId,
        string $branchId,
        string $courierId,
        ?string $fromAt,
        int $countedCents,
        ?string $note,
        ?string $createdBy,
    ): array {
        $stmt = $this->pdo->prepare(
            'INSERT INTO courier_settlements
                (tenant_id, branch_id, courier_id, from_at, counted_cash, note, created_by)
             VALUES (:tenant_id, :branch_id, :courier_id, :from_at, :counted, :note, :created_by)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'courier_id' => $courierId,
            'from_at' => $fromAt,
            'counted' => Money::toDecimalString($countedCents),
            'note' => $note,
            'created_by' => $createdBy,
        ]);
        return $stmt->fetch();
    }

    /**
     * Los cuadres ya hechos de una sucursal, del mas reciente al mas viejo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(string $tenantId, string $branchId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, u.name AS courier_name, q.name AS created_by_name
               FROM courier_settlements s
               LEFT JOIN users u ON u.id = s.courier_id
               LEFT JOIN users q ON q.id = s.created_by
              WHERE s.tenant_id = :tenant_id AND s.branch_id = :branch_id
              ORDER BY s.to_at DESC
              LIMIT :limit'
        );
        $stmt->bindValue('tenant_id', $tenantId);
        $stmt->bindValue('branch_id', $branchId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
