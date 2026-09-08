<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;
use App\Core\Row;

final class MenuItem
{
    /**
     * @param ModifierGroup[] $modifierGroups
     * @param string|null $taxRate fraccion decimal del impuesto, ya resuelta ("0.0800"); null = exento
     */
    public function __construct(
        public readonly string $id,
        public readonly string $categoryId,
        public readonly ?string $taxRateId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly int $basePriceCents,
        public readonly ?int $prepMinutes,
        public readonly bool $isAvailable,
        public readonly bool $isArchived,
        public readonly int $sortOrder,
        public readonly array $modifierGroups = [],
        public readonly ?string $taxRate = null,
        public readonly bool $taxIncludedInPrice = true,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param ModifierGroup[] $modifierGroups
     */
    public static function fromRow(array $row, array $modifierGroups = []): self
    {
        return new self(
            id: $row['id'],
            categoryId: $row['category_id'],
            taxRateId: $row['tax_rate_id'],
            name: $row['name'],
            description: $row['description'],
            basePriceCents: Money::fromDecimalString((string) $row['base_price']),
            prepMinutes: $row['prep_minutes'] === null ? null : (int) $row['prep_minutes'],
            isAvailable: Row::bool($row['is_available']),
            isArchived: Row::bool($row['is_archived']),
            sortOrder: (int) $row['sort_order'],
            modifierGroups: $modifierGroups,
            // Solo vienen cuando la consulta hizo el join con tax_rates
            // (get_item_with_modifiers en Python); si no, el producto se
            // trata como exento igual que alli.
            taxRate: $row['tax_rate'] ?? null,
            taxIncludedInPrice: isset($row['tax_included_in_price'])
                ? Row::bool($row['tax_included_in_price'])
                : true,
        );
    }
}
