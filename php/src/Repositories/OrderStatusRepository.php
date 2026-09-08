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
