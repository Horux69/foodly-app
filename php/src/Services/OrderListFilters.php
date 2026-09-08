<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\OrderStatus;

/**
 * Lo que el usuario pidio ver de la lista de pedidos.
 *
 * El filtro de estado va por categoria (`order_statuses.category`) y nunca
 * por `code`: cada restaurante bautiza sus estados como quiere, la categoria
 * es la parte que la plataforma entiende igual en todos.
 */
final class OrderListFilters
{
    public const LIMIT_DEFAULT = 25;
    public const LIMIT_MAX = 100;

    public function __construct(
        public readonly ?string $statusCategory = null,
        public readonly ?string $channel = null,
        /** Numero de pedido o telefono del cliente. */
        public readonly ?string $search = null,
        /** Fechas YYYY-MM-DD, leidas en la zona horaria de la sucursal. */
        public readonly ?string $fromDate = null,
        public readonly ?string $toDate = null,
        public readonly int $limit = self::LIMIT_DEFAULT,
        public readonly ?string $cursor = null,
    ) {
        if ($statusCategory !== null && !in_array($statusCategory, OrderStatus::CATEGORIES, true)) {
            throw new OrderError(
                "'status_category' debe ser una de: " . implode(', ', OrderStatus::CATEGORIES)
            );
        }
        if ($limit < 1 || $limit > self::LIMIT_MAX) {
            throw new OrderError("'limit' debe estar entre 1 y " . self::LIMIT_MAX);
        }
        if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
            throw new OrderError("'from_date' no puede ser posterior a 'to_date'");
        }
    }
}
