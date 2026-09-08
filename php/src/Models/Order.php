<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;

final class Order
{
    /** @param OrderItem[] $items */
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $branchId,
        public readonly string $statusId,
        public readonly string $orderNumber,
        public readonly string $channel,
        public readonly int $subtotalCents,
        public readonly int $taxTotalCents,
        public readonly int $deliveryFeeCents,
        public readonly int $discountCents,
        public readonly int $tipCents,
        public readonly int $totalCents,
        public readonly ?string $notes,
        public readonly string $createdAt,
        public readonly array $items = [],
        public readonly ?OrderStatusRow $status = null,
        public readonly ?string $tableCode = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param OrderItem[] $items
     */
    public static function fromRow(
        array $row,
        array $items = [],
        ?OrderStatusRow $status = null,
        ?string $tableCode = null,
    ): self {
        return new self(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            branchId: $row['branch_id'],
            statusId: $row['status_id'],
            orderNumber: $row['order_number'],
            channel: $row['channel'],
            subtotalCents: Money::fromDecimalString((string) $row['subtotal']),
            taxTotalCents: Money::fromDecimalString((string) $row['tax_total']),
            deliveryFeeCents: Money::fromDecimalString((string) $row['delivery_fee']),
            discountCents: Money::fromDecimalString((string) $row['discount']),
            tipCents: Money::fromDecimalString((string) $row['tip']),
            totalCents: Money::fromDecimalString((string) $row['total']),
            notes: $row['notes'],
            createdAt: $row['created_at'],
            items: $items,
            status: $status,
            tableCode: $tableCode,
        );
    }
}
