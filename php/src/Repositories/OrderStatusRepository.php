<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Row;
use App\Domain\StatusTransition;
use App\Models\OrderStatusRow;
use PDO;

final class OrderStatusRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getInitial(string $tenantId): ?OrderStatusRow
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM order_statuses WHERE tenant_id = :tenant_id AND is_initial = true'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : OrderStatusRow::fromRow($row);
    }

    public function get(string $tenantId, string $statusId): ?OrderStatusRow
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_statuses WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $statusId]);
        $row = $stmt->fetch();
        return $row === false ? null : OrderStatusRow::fromRow($row);
    }

    /** @return OrderStatusRow[] */
    public function listStatuses(string $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM order_statuses WHERE tenant_id = :tenant_id ORDER BY sort_order'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map(OrderStatusRow::fromRow(...), $stmt->fetchAll());
    }

    /** @return StatusTransition[] */
    public function listTransitions(string $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.* FROM order_status_transitions t
             JOIN order_statuses s ON s.id = t.from_status_id
             WHERE s.tenant_id = :tenant_id'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map(
            static fn (array $row) => new StatusTransition(
                $row['from_status_id'],
                $row['to_status_id'],
                $row['required_permission'],
            ),
            $stmt->fetchAll(),
        );
    }

    public function getByCode(string $tenantId, string $code): ?OrderStatusRow
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_statuses WHERE tenant_id = :tenant_id AND code = :code');
        $stmt->execute(['tenant_id' => $tenantId, 'code' => $code]);
        $row = $stmt->fetch();
        return $row === false ? null : OrderStatusRow::fromRow($row);
    }

    // ---------- Edicion desde la web (F5.2) ----------

    /**
     * Un estado nuevo nunca nace inicial: mover esa marca es su propia
     * operacion, porque hay que apagar la anterior en el mismo paso — el
     * indice unico parcial `idx_status_initial_per_tenant` no admite dos.
     */
    public function createStatus(
        string $tenantId,
        string $code,
        string $name,
        string $category,
        ?string $color,
        int $sortOrder,
        bool $isFinal,
    ): OrderStatusRow {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_statuses (tenant_id, code, name, category, color, sort_order, is_final)
             VALUES (:tenant_id, :code, :name, :category, :color, :sort_order, :is_final)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'color' => $color,
            'sort_order' => $sortOrder,
            'is_final' => Row::pgBool($isFinal),
        ]);
        return OrderStatusRow::fromRow($stmt->fetch());
    }

    public function updateStatus(
        string $statusId,
        string $name,
        string $category,
        ?string $color,
        int $sortOrder,
        bool $isFinal,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE order_statuses
                SET name = :name, category = :category, color = :color, sort_order = :sort_order, is_final = :is_final
              WHERE id = :id'
        );
        $stmt->execute([
            'id' => $statusId,
            'name' => $name,
            'category' => $category,
            'color' => $color,
            'sort_order' => $sortOrder,
            'is_final' => Row::pgBool($isFinal),
        ]);
    }

    /**
     * Mueve la marca de inicial. Las dos sentencias van en este orden a
     * proposito: `idx_status_initial_per_tenant` (indice unico parcial,
     * migracion 001) rechaza el segundo UPDATE si el anterior sigue marcado.
     */
    public function moveInitial(string $tenantId, string $statusId): void
    {
        $this->pdo->prepare('UPDATE order_statuses SET is_initial = false WHERE tenant_id = :tenant_id AND is_initial')
            ->execute(['tenant_id' => $tenantId]);
        $this->pdo->prepare('UPDATE order_statuses SET is_initial = true WHERE id = :id')
            ->execute(['id' => $statusId]);
    }

    public function deleteStatus(string $statusId): void
    {
        $this->pdo->prepare('DELETE FROM order_statuses WHERE id = :id')->execute(['id' => $statusId]);
    }

    /** Cuantos pedidos estan parados en ese estado ahora mismo. */
    public function countOrdersIn(string $statusId): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM orders WHERE status_id = :id');
        $stmt->execute(['id' => $statusId]);
        return (int) $stmt->fetchColumn();
    }

    /** Cuantas veces aparece en la bitacora: aunque ya no haya pedidos ahi, la historia lo referencia. */
    public function countHistoryFor(string $statusId): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM order_status_history WHERE status_id = :id');
        $stmt->execute(['id' => $statusId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Reemplaza las salidas de un estado por las que se le pasen.
     *
     * De una en una y no por edicion suelta porque asi la comprobacion de
     * "ningun estado se queda sin salida" corre sobre el resultado completo.
     *
     * @param array<int, array{0: string, 1: ?string}> $targets [to_status_id, required_permission]
     */
    public function setTransitionsFrom(string $fromStatusId, array $targets): void
    {
        $this->pdo->prepare('DELETE FROM order_status_transitions WHERE from_status_id = :id')
            ->execute(['id' => $fromStatusId]);

        foreach ($targets as [$toStatusId, $requiredPermission]) {
            $this->insertTransition($fromStatusId, $toStatusId, $requiredPermission);
        }
    }

    /**
     * Siembra un estado y devuelve su id — usado solo por TenantProvisioning,
     * que inserta el preset completo de estados y transiciones de un tenant
     * nuevo dentro de una misma transaccion.
     */
    public function insertStatus(
        string $tenantId,
        string $code,
        string $name,
        string $category,
        int $sortOrder,
        bool $isInitial,
        bool $isFinal,
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_statuses (tenant_id, code, name, category, sort_order, is_initial, is_final)
             VALUES (:tenant_id, :code, :name, :category, :sort_order, :is_initial, :is_final)
             RETURNING id'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'category' => $category,
            'sort_order' => $sortOrder,
            'is_initial' => Row::pgBool($isInitial),
            'is_final' => Row::pgBool($isFinal),
        ]);
        return (string) $stmt->fetchColumn();
    }

    public function insertTransition(string $fromStatusId, string $toStatusId, ?string $requiredPermission): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_status_transitions (from_status_id, to_status_id, required_permission)
             VALUES (:from_status_id, :to_status_id, :required_permission)'
        );
        $stmt->execute([
            'from_status_id' => $fromStatusId,
            'to_status_id' => $toStatusId,
            'required_permission' => $requiredPermission,
        ]);
    }
}
