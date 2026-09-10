<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;

final class MenuCategory
{
    /** @param MenuItem[] $items */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $name,
        public readonly int $sortOrder,
        public readonly bool $isActive,
        public readonly array $items = [],
        /** A que estacion de preparacion va lo de esta categoria (F8.1). */
        public readonly ?string $stationId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param MenuItem[] $items
     */
    public static function fromRow(array $row, array $items = []): self
    {
        return new self(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            name: $row['name'],
            sortOrder: (int) $row['sort_order'],
            isActive: Row::bool($row['is_active']),
            items: $items,
            stationId: $row['station_id'] ?? null,
        );
    }
}
