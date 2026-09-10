<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

final class Tenant
{
    /** @param array<mixed> $settings */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $businessType,
        public readonly string $currency,
        public readonly array $settings,
        public readonly bool $isActive,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            name: $row['name'],
            slug: $row['slug'],
            businessType: $row['business_type'],
            currency: $row['currency'],
            settings: Row::json($row['settings']),
            isActive: Row::bool($row['is_active']),
        );
    }
}
