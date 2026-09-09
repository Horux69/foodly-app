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

/** Linea ya resuelta: producto, modificadores y lo que el dominio necesita para calcular. */
final class ResolvedLine
{
    /**
     * @param Modifier[] $modifiers
     * @param array<int, array{item_id: string, name: string, quantity: int}> $components
     *        lo que lleva el combo, por unidad; vacio si el producto no lo es
     */
    public function __construct(
        public readonly MenuItem $item,
        public readonly OrderLineInput $line,
        public readonly array $modifiers,
        public readonly LineInput $lineInput,
        public readonly array $components = [],
    ) {
    }
}
