<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

/** Fila cruda de branch_schedules — distinta de Domain\ScheduleWindow, que es
 *  el value object que ya entiende is_branch_open(). */
final class BranchScheduleRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $branchId,
        public readonly int $weekday,
        public readonly string $opensAt,
        public readonly string $closesAt,
        public readonly ?string $channel,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            branchId: $row['branch_id'],
            weekday: (int) $row['weekday'],
            opensAt: $row['opens_at'],
            closesAt: $row['closes_at'],
            channel: $row['channel'],
            isActive: Row::bool($row['is_active']),
        );
    }
}
