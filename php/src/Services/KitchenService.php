<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\OrderRepository;

/**
 * Tablero de cocina (KDS).
 *
 * Se apoya en order_statuses.category, nunca en el code: asi funciona igual
 * aunque cada restaurante nombre sus estados distinto.
 */
final class KitchenService
{
    private const KDS_CATEGORIES = ['new', 'kitchen', 'ready'];

    /** @return KitchenOrder[] */
    public static function getBoard(string $tenantId, string $branchId): array
    {
        $orders = (new OrderRepository(Database::app()))
            ->listByStatusCategories($tenantId, $branchId, self::KDS_CATEGORIES);

        [$machine, $byId] = OrderStatusService::buildMachine($tenantId);

        return array_map(
            static fn ($order) => new KitchenOrder(
                $order,
                array_map(static fn ($s) => $byId[$s->id], $machine->allowedFrom($order->statusId)),
            ),
            $orders,
        );
    }
}
