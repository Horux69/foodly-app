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

        return [
            // Que columnas mostrar lo decide la configuracion del
            // restaurante, no la pantalla: ver KitchenService::columnsFor.
            'columns' => $board->columns,
            'orders' => array_map(self::orderOut(...), $board->orders),
            // Lo despachado hace poco, para recuperar el que se marco listo
            // por error. Trae sus next_statuses: si el tenant no configuro
            // camino de vuelta, no habra nada que pulsar y eso es correcto.
            'dispatched' => array_map(self::orderOut(...), $board->dispatched),
        ];
    }

    private static function orderOut(KitchenOrder $entry): array
    {
        return [
            'id' => $entry->order->id,
            'order_number' => $entry->order->orderNumber,
            'channel' => $entry->order->channel,
            'table_code' => $entry->order->tableCode,
            'created_at' => $entry->order->createdAt,
            'status' => OrderController::statusOut($entry->order->status),
            'items' => array_map(static fn ($item) => [
                // El id lo usa el tablero para marcar lineas ya preparadas.
                'id' => $item->id,
                'name_snapshot' => $item->nameSnapshot,
                'quantity' => $item->quantity,
                'notes' => $item->notes,
                'modifiers' => array_map(static fn ($m) => $m->nameSnapshot, $item->modifiers),
                // Un combo llega al tablero como una linea con lo que lleva
                // debajo: la cocina necesita la lista de platos, no el nombre
                // comercial del paquete.
                'components' => array_map(static fn ($c) => [
                    'name_snapshot' => $c->nameSnapshot,
                    'quantity' => $c->quantity,
                ], $item->components),
            ], $entry->order->items),
            'next_statuses' => array_map(OrderController::statusOut(...), $entry->nextStatuses),
        ];
    }
}
