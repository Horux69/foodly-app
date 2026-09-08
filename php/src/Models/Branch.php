<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

final class Branch
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $name,
        public readonly string $code,
        public readonly string $timezone,
        public readonly ?string $address,
        public readonly ?string $phone,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            name: $row['name'],
            code: $row['code'],
            timezone: $row['timezone'],
            address: $row['address'],
            phone: $row['phone'],
            isActive: Row::bool($row['is_active']),
        );
    }
}
