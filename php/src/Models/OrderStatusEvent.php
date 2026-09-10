<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Una entrada de la bitacora de un pedido: a que estado paso, cuando, quien
 * lo movio y con que nota.
 *
 * `order_status_history` se escribia desde el primer dia y solo se leia para
 * el reporte de tiempos de preparacion, asi que nadie podia ver quien cambio
 * que. Es la respuesta a "¿por que este pedido se canceló?".
 *
 * `changedByName` puede ser null: el usuario pudo darse de baja
 * (`changed_by` es ON DELETE SET NULL) y el pedido inicial lo crea el
 * sistema cuando entra por una integracion.
 */
final class OrderStatusEvent
{
    public function __construct(
        public readonly string $id,
        public readonly OrderStatusRow $status,
        public readonly ?string $changedByName,
        public readonly ?string $note,
        public readonly string $changedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            status: OrderStatusRow::fromRow([
                'id' => $row['s_id'],
                'code' => $row['s_code'],
                'name' => $row['s_name'],
                'category' => $row['s_category'],
                'color' => $row['s_color'],
                'sort_order' => $row['s_sort_order'],
                'is_initial' => $row['s_is_initial'],
                'is_final' => $row['s_is_final'],
            ]),
            changedByName: $row['changed_by_name'],
            note: $row['note'],
            changedAt: $row['changed_at'],
        );
    }
}
