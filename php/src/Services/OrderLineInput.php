<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\BranchSchedule;
use App\Domain\LineInput;
use App\Domain\MenuPricing;
use App\Domain\ModifierGroupConstraint;
use App\Domain\ModifierValidation;
use App\Domain\ModifierValidationError;
use App\Domain\OrderTotals;
use App\Domain\OrderTotalsCalculator;
use App\Domain\OrderTotalsError;
use App\Domain\ScheduleWindow;
use App\Domain\TenantSettings;
use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Order;
use App\Repositories\BranchRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\MenuRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Repositories\TableRepository;
use App\Repositories\TenantRepository;

/** Una linea pedida por el cliente, antes de resolverla contra el menu. */
final class OrderLineInput
{
    /** @param string[] $modifierIds */
    public function __construct(
        public readonly string $menuItemId,
        public readonly int $quantity,
        public readonly array $modifierIds = [],
        public readonly ?string $notes = null,
    ) {
    }
}
