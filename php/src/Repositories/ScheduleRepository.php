<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Row;
use App\Models\BranchScheduleRow;
use PDO;

/**
 * Franjas horarias de una sucursal.
 *
 * Aparte de BranchRepository —que ya tiene `getSchedules` para la comprobacion
 * de "esta abierto ahora"— porque eso es una lectura de operacion y esto es el
 * CRUD de configuracion: aquella filtra por `is_active` y esta necesita ver
 * tambien las apagadas para poder mostrarlas y encenderlas.
 */
final class ScheduleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Todas las franjas de la sucursal, activas o no, en el orden en que se
     * leen: por dia y por hora de apertura.
     *
     * @return BranchScheduleRow[]
     */
    public function listForBranch(string $branchId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM branch_schedules WHERE branch_id = :branch_id ORDER BY weekday, opens_at, channel NULLS FIRST'
        );
        $stmt->execute(['branch_id' => $branchId]);
        return array_map(BranchScheduleRow::fromRow(...), $stmt->fetchAll());
    }

    public function get(string $scheduleId): ?BranchScheduleRow
    {
        $stmt = $this->pdo->prepare('SELECT * FROM branch_schedules WHERE id = :id');
        $stmt->execute(['id' => $scheduleId]);
        $row = $stmt->fetch();
        return $row === false ? null : BranchScheduleRow::fromRow($row);
    }

    public function create(string $branchId, int $weekday, string $opensAt, string $closesAt, ?string $channel): BranchScheduleRow
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO branch_schedules (branch_id, weekday, opens_at, closes_at, channel)
             VALUES (:branch_id, :weekday, :opens_at, :closes_at, :channel)
             RETURNING *'
        );
        $stmt->execute([
            'branch_id' => $branchId,
            'weekday' => $weekday,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'channel' => $channel,
        ]);
        return BranchScheduleRow::fromRow($stmt->fetch());
    }

    public function setActive(string $scheduleId, bool $isActive): BranchScheduleRow
    {
        $stmt = $this->pdo->prepare(
            'UPDATE branch_schedules SET is_active = :is_active WHERE id = :id RETURNING *'
        );
        $stmt->execute(['is_active' => Row::pgBool($isActive), 'id' => $scheduleId]);
        return BranchScheduleRow::fromRow($stmt->fetch());
    }

    public function delete(string $scheduleId): void
    {
        $this->pdo->prepare('DELETE FROM branch_schedules WHERE id = :id')->execute(['id' => $scheduleId]);
    }
}
