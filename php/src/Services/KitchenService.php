<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;

/**
 * Tablero de cocina (KDS).
 *
 * Se apoya en order_statuses.category, nunca en el code: asi funciona igual
 * aunque cada restaurante nombre sus estados distinto.
 */
final class KitchenService
{
    /**
     * Las categorias que puede mostrar el tablero, en el orden en que fluye
     * el trabajo. 'in_transit' esta aqui para los domicilios en camino; que
     * aparezca o no lo decide la configuracion del restaurante, no esta
     * lista.
     */
    private const KDS_CATEGORIES = ['new', 'kitchen', 'ready', 'in_transit'];

    /**
     * Cuanto sigue viendose un pedido despues de despacharse.
     *
     * Es la ventana para recuperar el que se marco listo por error. Mas alla
     * de eso el tablero se llenaria de pedidos del dia que ya no importan.
     */
    private const DISPATCHED_MINUTES = 30;

    public static function getBoard(string $tenantId, string $branchId): KitchenBoard
    {
        $orders = new OrderRepository(Database::app());
        [$machine, $byId] = OrderStatusService::buildMachine($tenantId);

        $conSiguientes = static fn ($order) => new KitchenOrder(
            $order,
            array_map(static fn ($s) => $byId[$s->id], $machine->allowedFrom($order->statusId)),
        );

        return new KitchenBoard(
            self::columnsFor($tenantId),
            array_map(
                $conSiguientes,
                $orders->listByStatusCategories($tenantId, $branchId, self::KDS_CATEGORIES),
            ),
            array_map(
                $conSiguientes,
                $orders->listByStatusCategories($tenantId, $branchId, ['completed'], self::DISPATCHED_MINUTES),
            ),
        );
    }

    /**
     * Las columnas que este restaurante usa de verdad.
     *
     * Salen de los estados que tiene configurados: uno de comida rapida no
     * tiene ningun estado 'in_transit' y esa columna, siempre vacia, seria
     * ruido en la pantalla que mas se mira de lejos. Por configuracion, no
     * por un condicional sobre el tenant.
     *
     * @return string[]
     */
    private static function columnsFor(string $tenantId): array
    {
        $suyas = [];
        foreach ((new OrderStatusRepository(Database::app()))->listStatuses($tenantId) as $status) {
            $suyas[$status->category] = true;
        }

        return array_values(array_filter(
            self::KDS_CATEGORIES,
            static fn (string $categoria) => isset($suyas[$categoria]),
        ));
    }
}
