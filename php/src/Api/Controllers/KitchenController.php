<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Deps;
use App\Domain\KitchenTickets;
use App\Services\KitchenOrder;
use App\Services\KitchenService;

/** Equivalente PHP de app/api/v1/kitchen.py. */
final class KitchenController
{
    public static function board(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');
        $board = KitchenService::getBoard($ctx->tenantId, Deps::activeBranchId($ctx));
        self::$routing = $board->routing;
        self::$nombresEstacion = array_column($board->stations, 'name', 'id');
        self::$tiempos = $board->courses;

        return [
            // Que columnas mostrar lo decide la configuracion del
            // restaurante, no la pantalla: ver KitchenService::columnsFor.
            'columns' => $board->columns,
            'orders' => array_map(self::orderOut(...), $board->orders),
            // Lo despachado hace poco, para recuperar el que se marco listo
            // por error. Trae sus next_statuses: si el tenant no configuro
            // camino de vuelta, no habra nada que pulsar y eso es correcto.
            'dispatched' => array_map(self::orderOut(...), $board->dispatched),
            // Las estaciones activas, para el filtro del tablero. Vacio
            // significa que este restaurante no las usa y ve todo junto.
            'stations' => $board->stations,
        ];
    }

    /** @var array<string, string> id de categoria => id de estacion, del tablero en curso */
    private static array $routing = [];

    /** @var array<string, string> id de estacion => como se llama */
    private static array $nombresEstacion = [];

    /** @var string[] los tiempos del restaurante, en orden */
    private static array $tiempos = [];

    /**
     * Las lineas de un pedido, repartidas en comandas por tiempo y estacion.
     *
     * Quien reparte es Domain\StationRouting y no la pantalla: la comanda
     * impresa y el tablero tienen que decir lo mismo, y un cliente nuevo
     * —el agente de WhatsApp— no deberia volver a implementar la regla.
     *
     * @param array<int, array<string, mixed>> $lineas
     */
    public static function ticketsOut(array $lineas): array
    {
        return KitchenTickets::build($lineas, self::$routing, self::$nombresEstacion, self::$tiempos);
    }

    private static function orderOut(KitchenOrder $entry): array
    {
        $lineas = array_map(static fn ($item) => [
            // El id lo usa el tablero para marcar lineas ya preparadas.
            'id' => $item->id,
            'category_id' => $item->categoryId,
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
            'station_id' => self::$routing[$item->categoryId ?? ''] ?? null,
            'station_name' => self::$nombresEstacion[self::$routing[$item->categoryId ?? ''] ?? ''] ?? null,
            'course' => $item->course,
            'fired_at' => $item->firedAt,
        ], $entry->order->items);

        return [
            'id' => $entry->order->id,
            'order_number' => $entry->order->orderNumber,
            'channel' => $entry->order->channel,
            'table_code' => $entry->order->tableCode,
            'created_at' => $entry->order->createdAt,
            'status' => OrderController::statusOut($entry->order->status),
            // Solo lo marchado: una linea que el mesero todavia no mando no
            // es trabajo de la cocina, y verla ahi la haria preparar el
            // postre con las entradas.
            'items' => array_values(array_filter($lineas, static fn ($l) => $l['fired_at'] !== null)),
            // Una comanda por tiempo y estacion, ya repartida por el dominio.
            'kitchen_tickets' => self::ticketsOut($lineas),
            // Lo que falta por marchar: un pedido al que le falta el postre
            // no esta terminado, y sin decirlo la cocina lo da por
            // despachado.
            'pending_courses' => KitchenTickets::pending($lineas, self::$tiempos),
            'next_statuses' => array_map(OrderController::statusOut(...), $entry->nextStatuses),
        ];
    }
}
