<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

/** Una caja registradora de una sucursal (F7.4). */
final class Register
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $branchId,
        public readonly string $name,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            branchId: $row['branch_id'],
            name: $row['name'],
            isActive: Row::bool($row['is_active']),
        );
    }
}
