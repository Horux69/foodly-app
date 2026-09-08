<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\PaymentBalance;
use App\Models\DeliveryInfo;
use App\Models\Order;
use App\Models\OrderStatusRow;

/**
 * Una pagina de la lista de pedidos, con el saldo de cada uno ya resuelto.
 *
 * El saldo viaja aqui y no se pide pedido por pedido: la lista disparaba una
 * peticion de saldo por fila, hasta 51 llamadas para pintar una pantalla.
 */
final class OrderListPage
{
    /**
     * @param Order[] $orders
     * @param array<string, PaymentBalance> $balances saldo por id de pedido
     * @param array<string, DeliveryInfo> $deliveries entrega por id de pedido,
     *        solo para los que la tienen
     * @param array<string, OrderStatusRow[]> $nextStatuses vacio si no se pidieron
     */
    public function __construct(
        public readonly array $orders,
        public readonly array $balances,
        public readonly ?string $nextCursor,
        public readonly array $deliveries = [],
        /** @var array<string, OrderStatusRow[]> proximos estados por id de pedido */
        public readonly array $nextStatuses = [],
    ) {
    }
}
