<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;

final class Payment
{
    public function __construct(
        public readonly string $id,
        public readonly string $orderId,
        public readonly string $method,
        public readonly string $status,
        public readonly int $amountCents,
        public readonly ?string $externalReference,
        public readonly ?string $paidAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            orderId: $row['order_id'],
            method: $row['method'],
            status: $row['status'],
            amountCents: Money::fromDecimalString((string) $row['amount']),
            externalReference: $row['external_reference'],
            paidAt: $row['paid_at'],
        );
    }
}
