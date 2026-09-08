<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\PaymentBalance;
use App\Models\Order;

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
     */
    public function __construct(
        public readonly array $orders,
        public readonly array $balances,
        public readonly ?string $nextCursor,
    ) {
    }
}
