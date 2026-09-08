<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Row;
use App\Domain\OrderStatus;

/**
 * Fila de order_statuses con todo lo que muestra la interfaz (nombre, color).
 *
 * Distinta de Domain\OrderStatus a proposito: el dominio solo necesita saber
 * la categoria y si el estado es inicial o final, y no tiene por que enterarse
 * de como se pinta.
 */
final class OrderStatusRow
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $category,
        public readonly ?string $color,
        public readonly int $sortOrder,
        public readonly bool $isInitial,
        public readonly bool $isFinal,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            code: $row['code'],
            name: $row['name'],
            category: $row['category'],
            color: $row['color'],
            sortOrder: (int) $row['sort_order'],
            isInitial: Row::bool($row['is_initial']),
            isFinal: Row::bool($row['is_final']),
        );
    }

    public function toDomain(): OrderStatus
    {
        return new OrderStatus($this->id, $this->code, $this->category, $this->isInitial, $this->isFinal);
    }
}
