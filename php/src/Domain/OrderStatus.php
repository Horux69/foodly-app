<?php

declare(strict_types=1);

namespace App\Domain;

final class OrderStatus
{
    /**
     * El vocabulario normalizado de la plataforma. Los nombres y los codigos
     * de los estados los pone cada restaurante; estas seis categorias no, y
     * son las que entienden el KDS, los reportes y los filtros.
     *
     * Espejo del CHECK de order_statuses.category en
     * db/migrations/001_initial_schema.sql.
     */
    public const CATEGORIES = ['new', 'kitchen', 'ready', 'in_transit', 'completed', 'cancelled'];

    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $category,
        public readonly bool $isInitial,
        public readonly bool $isFinal,
    ) {
    }
}
