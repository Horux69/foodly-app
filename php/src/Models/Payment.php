<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Money;

/**
 * Una fila de payments: un cobro, o el reembolso de uno.
 *
 * Los distingue `refundOfPaymentId`: si apunta a algo, esta fila devolvio
 * plata en vez de recibirla. El importe es positivo en los dos casos —el
 * signo lo pone el sentido de la fila, no el numero.
 */
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
        public readonly ?string $refundOfPaymentId = null,
        public readonly ?string $createdBy = null,
        public readonly ?string $note = null,
    ) {
    }

    public function isRefund(): bool
    {
        return $this->refundOfPaymentId !== null;
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
            refundOfPaymentId: $row['refund_of_payment_id'] ?? null,
            createdBy: $row['created_by'] ?? null,
            note: $row['note'] ?? null,
        );
    }
}
