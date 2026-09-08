<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Row;
use App\Models\Branch;
use App\Models\BranchScheduleRow;
use PDO;

final class BranchRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $tenantId, string $branchId): ?Branch
    {
        $stmt = $this->pdo->prepare('SELECT * FROM branches WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $branchId]);
        $row = $stmt->fetch();
        return $row === false ? null : Branch::fromRow($row);
    }

    public function getByCode(string $tenantId, string $code): ?Branch
    {
        $stmt = $this->pdo->prepare('SELECT * FROM branches WHERE tenant_id = :tenant_id AND code = :code');
        $stmt->execute(['tenant_id' => $tenantId, 'code' => $code]);
        $row = $stmt->fetch();
        return $row === false ? null : Branch::fromRow($row);
    }

    /** @return Branch[] */
    public function listForTenant(string $tenantId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM branches WHERE tenant_id = :tenant_id ORDER BY name');
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map(Branch::fromRow(...), $stmt->fetchAll());
    }

    public function create(
        string $tenantId,
        string $name,
        string $code,
        string $timezone,
        ?string $address,
        ?string $phone,
    ): Branch {
        $stmt = $this->pdo->prepare(
            'INSERT INTO branches (tenant_id, name, code, timezone, address, phone)
             VALUES (:tenant_id, :name, :code, :timezone, :address, :phone)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => $code,
            'timezone' => $timezone,
            'address' => $address,
            'phone' => $phone,
        ]);
        return Branch::fromRow($stmt->fetch());
    }

    public function setActive(string $tenantId, string $branchId, bool $isActive): Branch
    {
        $stmt = $this->pdo->prepare(
            'UPDATE branches SET is_active = :is_active WHERE tenant_id = :tenant_id AND id = :id RETURNING *'
        );
        $stmt->execute(['is_active' => Row::pgBool($isActive), 'tenant_id' => $tenantId, 'id' => $branchId]);
        return Branch::fromRow($stmt->fetch());
    }

    /** @return BranchScheduleRow[] */
    public function getSchedules(string $branchId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM branch_schedules WHERE branch_id = :branch_id AND is_active = true'
        );
        $stmt->execute(['branch_id' => $branchId]);
        return array_map(BranchScheduleRow::fromRow(...), $stmt->fetchAll());
    }
}
