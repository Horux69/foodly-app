<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

final class Table
{
    public function __construct(
        public readonly string $id,
        public readonly string $branchId,
        public readonly string $code,
        public readonly int $capacity,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            branchId: $row['branch_id'],
            code: $row['code'],
            capacity: (int) $row['capacity'],
            isActive: Row::bool($row['is_active']),
        );
    }
}
