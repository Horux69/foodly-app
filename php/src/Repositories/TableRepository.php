<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Table;
use PDO;

final class TableRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getByCode(string $branchId, string $code): ?Table
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tables WHERE branch_id = :branch_id AND code = :code AND is_active = true'
        );
        $stmt->execute(['branch_id' => $branchId, 'code' => $code]);
        $row = $stmt->fetch();
        return $row === false ? null : Table::fromRow($row);
    }

    /** @return Table[] */
    public function listForBranch(string $branchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tables WHERE branch_id = :branch_id ORDER BY code');
        $stmt->execute(['branch_id' => $branchId]);
        return array_map(Table::fromRow(...), $stmt->fetchAll());
    }

    public function create(string $branchId, string $code, int $capacity): Table
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tables (branch_id, code, capacity) VALUES (:branch_id, :code, :capacity) RETURNING *'
        );
        $stmt->execute(['branch_id' => $branchId, 'code' => $code, 'capacity' => $capacity]);
        return Table::fromRow($stmt->fetch());
    }
}
