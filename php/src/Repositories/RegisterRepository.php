<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Row;
use App\Models\Register;
use PDO;

/** Las cajas de una sucursal (F7.4). */
final class RegisterRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return Register[] */
    public function listForBranch(string $branchId, bool $soloActivas = false): array
    {
        $filtro = $soloActivas ? ' AND is_active' : '';
        $stmt = $this->pdo->prepare(
            "SELECT * FROM registers WHERE branch_id = :branch_id{$filtro} ORDER BY name"
        );
        $stmt->execute(['branch_id' => $branchId]);
        return array_map(Register::fromRow(...), $stmt->fetchAll());
    }

    public function get(string $tenantId, string $id): ?Register
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registers WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : Register::fromRow($row);
    }

    public function getByName(string $branchId, string $name): ?Register
    {
        $stmt = $this->pdo->prepare('SELECT * FROM registers WHERE branch_id = :branch_id AND name = :name');
        $stmt->execute(['branch_id' => $branchId, 'name' => $name]);
        $row = $stmt->fetch();
        return $row === false ? null : Register::fromRow($row);
    }

    public function create(string $tenantId, string $branchId, string $name): Register
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO registers (tenant_id, branch_id, name) VALUES (:tenant_id, :branch_id, :name) RETURNING *'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'branch_id' => $branchId, 'name' => $name]);
        return Register::fromRow($stmt->fetch());
    }

    public function update(string $tenantId, string $id, string $name, bool $isActive): ?Register
    {
        $stmt = $this->pdo->prepare(
            'UPDATE registers SET name = :name, is_active = :is_active
              WHERE tenant_id = :tenant_id AND id = :id RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'id' => $id,
            'name' => $name,
            'is_active' => Row::pgBool($isActive),
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : Register::fromRow($row);
    }

    /** Cuantos turnos se abrieron en esta caja: con uno, ya no se borra. */
    public function sessionCount(string $id): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM cash_sessions WHERE register_id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    public function delete(string $tenantId, string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM registers WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
