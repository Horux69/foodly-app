<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

final class Role
{
    /** @param string[] $permissionCodes */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $code,
        public readonly string $name,
        public readonly bool $isSystem,
        public readonly array $permissionCodes,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $permissionCodes
     */
    public static function fromRow(array $row, array $permissionCodes = []): self
    {
        return new self(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            code: $row['code'],
            name: $row['name'],
            isSystem: Row::bool($row['is_system']),
            permissionCodes: $permissionCodes,
        );
    }
}
