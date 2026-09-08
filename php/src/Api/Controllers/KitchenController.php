<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Deps;
use App\Services\KitchenOrder;
use App\Services\KitchenService;

/** Equivalente PHP de app/api/v1/kitchen.py. */
final class KitchenController
{
    public static function board(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');
        $board = KitchenService::getBoard($ctx->tenantId, Deps::activeBranchId($ctx));

        return array_map(static fn (KitchenOrder $entry) => [
            'id' => $entry->order->id,
            'order_number' => $entry->order->orderNumber,
            'channel' => $entry->order->channel,
            'table_code' => $entry->order->tableCode,
            'created_at' => $entry->order->createdAt,
            'status' => OrderController::statusOut($entry->order->status),
            'items' => array_map(static fn ($item) => [
                'name_snapshot' => $item->nameSnapshot,
                'quantity' => $item->quantity,
                'notes' => $item->notes,
                'modifiers' => array_map(static fn ($m) => $m->nameSnapshot, $item->modifiers),
            ], $entry->order->items),
            'next_statuses' => array_map(OrderController::statusOut(...), $entry->nextStatuses),
        ], $board);
    }
}
