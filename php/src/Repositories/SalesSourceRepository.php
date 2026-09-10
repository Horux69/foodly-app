<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Row;
use App\Models\SalesSource;
use PDO;

/** Los origenes de venta del tenant (F9.4). */
final class SalesSourceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return SalesSource[] */
    public function listForTenant(string $tenantId, bool $soloActivos = false): array
    {
        $filtro = $soloActivos ? ' AND is_active' : '';
        $stmt = $this->pdo->prepare(
            "SELECT * FROM sales_sources WHERE tenant_id = :tenant_id{$filtro} ORDER BY name"
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map(SalesSource::fromRow(...), $stmt->fetchAll());
    }

    public function get(string $tenantId, string $id): ?SalesSource
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sales_sources WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : SalesSource::fromRow($row);
    }

    public function getByName(string $tenantId, string $name): ?SalesSource
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sales_sources WHERE tenant_id = :tenant_id AND name = :name');
        $stmt->execute(['tenant_id' => $tenantId, 'name' => $name]);
        $row = $stmt->fetch();
        return $row === false ? null : SalesSource::fromRow($row);
    }

    public function create(string $tenantId, string $name, float $commissionPercent): SalesSource
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sales_sources (tenant_id, name, commission_percent)
             VALUES (:tenant_id, :name, :commission) RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'name' => $name,
            'commission' => number_format($commissionPercent, 2, '.', ''),
        ]);
        return SalesSource::fromRow($stmt->fetch());
    }

    public function update(string $tenantId, string $id, string $name, float $commissionPercent, bool $isActive): ?SalesSource
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sales_sources
                SET name = :name, commission_percent = :commission, is_active = :is_active
              WHERE tenant_id = :tenant_id AND id = :id
              RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'id' => $id,
            'name' => $name,
            'commission' => number_format($commissionPercent, 2, '.', ''),
            'is_active' => Row::pgBool($isActive),
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : SalesSource::fromRow($row);
    }

    /** Cuantos pedidos entraron por este canal: un canal con ventas no se borra. */
    public function orderCount(string $tenantId, string $id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM orders WHERE tenant_id = :tenant_id AND sales_source_id = :id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    public function delete(string $tenantId, string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM sales_sources WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
