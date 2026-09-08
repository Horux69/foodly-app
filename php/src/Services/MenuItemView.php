<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\MenuPricing;
use App\Models\BranchMenuOverride;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Repositories\BranchRepository;
use App\Repositories\MenuRepository;
use App\Repositories\TaxRateRepository;

/** Producto tal como lo ve quien vende: precio y disponibilidad ya resueltos para su sucursal. */
final class MenuItemView
{
    /** @param \App\Models\ModifierGroup[] $modifierGroups */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly int $priceCents,
        public readonly bool $isAvailable,
        public readonly array $modifierGroups,
    ) {
    }
}
