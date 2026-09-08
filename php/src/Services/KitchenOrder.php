<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\OrderStatusRow;

final class KitchenOrder
{
    /** @param OrderStatusRow[] $nextStatuses */
    public function __construct(
        public readonly Order $order,
        public readonly array $nextStatuses,
    ) {
    }
}
