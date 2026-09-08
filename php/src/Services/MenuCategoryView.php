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

final class MenuCategoryView
{
    /** @param MenuItemView[] $items */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $items,
    ) {
    }
}
