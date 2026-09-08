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

    /**
     * Lo cobrado por cada pedido, en centavos, para una lista entera.
     *
     * Solo cuenta los pagos en estado 'paid', igual que
     * PaymentService::getBalanceForOrder: un cobro pendiente o fallido no es
     * plata que haya entrado. La resta contra el total sigue siendo del
     * dominio (Domain\PaymentBalance); aqui solo se suma lo que entro.
     *
     * @param string[] $orderIds
     * @return array<string, int>
     */
    public function paidTotalsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT order_id, SUM(amount) AS paid
             FROM payments
             WHERE status = 'paid' AND order_id IN ({$placeholders})
             GROUP BY order_id"
        );
        $stmt->execute(array_values($orderIds));

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[$row['order_id']] = Money::fromDecimalString((string) $row['paid']);
        }
        return $totals;
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
