<?php

declare(strict_types=1);

namespace App\Models;

final class Customer
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $phone,
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            phone: $row['phone'],
            name: $row['name'],
            email: $row['email'],
            createdAt: $row['created_at'],
        );
    }
}
