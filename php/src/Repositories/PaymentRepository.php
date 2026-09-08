<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Money;
use App\Models\Payment;
use PDO;

final class PaymentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return Payment[] */
    public function listForOrder(string $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE order_id = :order_id ORDER BY created_at');
        $stmt->execute(['order_id' => $orderId]);
        return array_map(Payment::fromRow(...), $stmt->fetchAll());
    }

    public function getByIdempotencyKey(string $orderId, string $key): ?Payment
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM payments WHERE order_id = :order_id AND idempotency_key = :key'
        );
        $stmt->execute(['order_id' => $orderId, 'key' => $key]);
        $row = $stmt->fetch();
        return $row === false ? null : Payment::fromRow($row);
    }

    public function create(
        string $orderId,
        string $method,
        string $status,
        int $amountCents,
        ?string $externalReference,
        ?string $idempotencyKey,
        ?string $paidAt,
    ): Payment {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments (order_id, method, status, amount, external_reference, idempotency_key, paid_at)
             VALUES (:order_id, :method, :status, :amount, :external_reference, :idempotency_key, :paid_at)
             RETURNING *'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'method' => $method,
            'status' => $status,
            'amount' => Money::toDecimalString($amountCents),
            'external_reference' => $externalReference,
            'idempotency_key' => $idempotencyKey,
            'paid_at' => $paidAt,
        ]);
        return Payment::fromRow($stmt->fetch());
    }
}
