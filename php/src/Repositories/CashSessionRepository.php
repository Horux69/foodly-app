<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Money;
use App\Core\Row;
use App\Domain\CashMovement;
use App\Domain\DrawerMovement;
use App\Models\CashSession;
use PDO;

final class CashSessionRepository
{
    /** El nombre de quien abrio y de quien cerro, resueltos en la misma consulta. */
    private const SELECT_SESSION =
        'SELECT s.*, ua.name AS opened_by_name, uc.name AS closed_by_name
         FROM cash_sessions s
         LEFT JOIN users ua ON ua.id = s.opened_by
         LEFT JOIN users uc ON uc.id = s.closed_by';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function open(string $tenantId, string $branchId, ?string $openedBy, int $openingFloatCents): CashSession
    {
        $stmt = $this->pdo->prepare(
            // El consecutivo lo da Postgres en la misma sentencia: dos
            // cajeros abriendo a la vez no pueden sacar el mismo numero.
            'INSERT INTO cash_sessions (tenant_id, branch_id, opened_by, opening_float, session_number)
             VALUES (:tenant_id, :branch_id, :opened_by, :opening_float, next_cash_number(:branch_id))
             RETURNING id'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'opened_by' => $openedBy,
            'opening_float' => Money::toDecimalString($openingFloatCents),
        ]);

        return $this->get($tenantId, (string) $stmt->fetchColumn());
    }

    public function get(string $tenantId, string $sessionId): ?CashSession
    {
        $stmt = $this->pdo->prepare(self::SELECT_SESSION . ' WHERE s.tenant_id = :tenant_id AND s.id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $sessionId]);
        $row = $stmt->fetch();
        return $row === false ? null : CashSession::fromRow($row);
    }

    /**
     * El turno abierto de la sucursal, si lo hay.
     *
     * Que solo pueda haber uno lo garantiza el indice unico parcial de la
     * migracion 006, no este LIMIT.
     */
    public function currentForBranch(string $tenantId, string $branchId): ?CashSession
    {
        $stmt = $this->pdo->prepare(
            self::SELECT_SESSION
            . ' WHERE s.tenant_id = :tenant_id AND s.branch_id = :branch_id AND s.closed_at IS NULL'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'branch_id' => $branchId]);
        $row = $stmt->fetch();
        return $row === false ? null : CashSession::fromRow($row);
    }

    /** @return CashSession[] */
    public function listForBranch(string $tenantId, string $branchId, int $limit = 30): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT_SESSION
            . ' WHERE s.tenant_id = :tenant_id AND s.branch_id = :branch_id'
            . ' ORDER BY s.opened_at DESC LIMIT :limit'
        );
        $stmt->bindValue('tenant_id', $tenantId);
        $stmt->bindValue('branch_id', $branchId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(CashSession::fromRow(...), $stmt->fetchAll());
    }

    public function close(
        string $tenantId,
        string $sessionId,
        ?string $closedBy,
        int $countedCashCents,
        ?string $note,
    ): CashSession {
        // El WHERE exige que siga abierto: dos cierres simultaneos no se
        // pisan, el segundo no encuentra fila que actualizar.
        $stmt = $this->pdo->prepare(
            'UPDATE cash_sessions
                SET closed_at = now(), closed_by = :closed_by, counted_cash = :counted_cash, note = :note
              WHERE tenant_id = :tenant_id AND id = :id AND closed_at IS NULL'
        );
        $stmt->execute([
            'closed_by' => $closedBy,
            'counted_cash' => Money::toDecimalString($countedCashCents),
            'note' => $note,
            'tenant_id' => $tenantId,
            'id' => $sessionId,
        ]);

        return $this->get($tenantId, $sessionId);
    }

    /**
     * Los movimientos de plata de varios turnos, en una consulta.
     *
     * Que cuenta y que no es la misma regla que en el saldo de un pedido: un
     * cobro suma aunque este marcado 'refunded' —esa etiqueta solo dice que
     * ya se revirtio— y quien resta es su fila de reembolso.
     *
     * @param string[] $sessionIds
     * @return array<string, CashMovement[]> movimientos por id de turno
     */
    public function movementsForSessions(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT cash_session_id, method, amount, (refund_of_payment_id IS NOT NULL) AS is_refund
               FROM payments
              WHERE cash_session_id IN ({$placeholders})
                AND (
                     (refund_of_payment_id IS NULL AND status IN ('paid', 'refunded'))
                  OR (refund_of_payment_id IS NOT NULL AND status = 'paid')
                )
              ORDER BY created_at"
        );
        $stmt->execute(array_values($sessionIds));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['cash_session_id']][] = new CashMovement(
                $row['method'],
                Money::fromDecimalString((string) $row['amount']),
                Row::bool($row['is_refund']),
            );
        }
        return $result;
    }

    /**
     * Las entradas y salidas de efectivo de cada turno.
     *
     * Aparte de movementsForSessions —que lee `payments`— porque son otra
     * cosa: aquellos son ventas, estos son plata que entra o sale del cajon
     * sin pedido detras.
     *
     * @param string[] $sessionIds
     * @return array<string, DrawerMovement[]>
     */
    public function drawerForSessions(array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT cash_session_id, kind, amount, reason
               FROM cash_movements
              WHERE cash_session_id IN ({$placeholders})
              ORDER BY created_at"
        );
        $stmt->execute(array_values($sessionIds));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['cash_session_id']][] = new DrawerMovement(
                $row['kind'],
                Money::fromDecimalString((string) $row['amount']),
                $row['reason'],
            );
        }
        return $result;
    }

    /**
     * El detalle de los movimientos de un turno, para mostrarlos en pantalla.
     *
     * @return array<int, array{id: string, kind: string, reason: string, amount: int, created_at: string, by_name: ?string}>
     */
    public function drawerDetail(string $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.kind, m.reason, m.amount, m.created_at, u.name AS by_name
               FROM cash_movements m
               LEFT JOIN users u ON u.id = m.created_by
              WHERE m.cash_session_id = :id
           ORDER BY m.created_at'
        );
        $stmt->execute(['id' => $sessionId]);

        return array_map(static fn (array $row) => [
            'id' => $row['id'],
            'kind' => $row['kind'],
            'reason' => $row['reason'],
            'amount' => Money::fromDecimalString((string) $row['amount']),
            'created_at' => $row['created_at'],
            'by_name' => $row['by_name'],
        ], $stmt->fetchAll());
    }

    public function addDrawerMovement(
        string $sessionId,
        string $kind,
        int $amountCents,
        string $reason,
        ?string $createdBy,
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cash_movements (cash_session_id, kind, amount, reason, created_by)
             VALUES (:session, :kind, :amount, :reason, :by)
             RETURNING id'
        );
        $stmt->execute([
            'session' => $sessionId,
            'kind' => $kind,
            'amount' => Money::toDecimalString($amountCents),
            'reason' => $reason,
            'by' => $createdBy,
        ]);
        return (string) $stmt->fetchColumn();
    }
}
