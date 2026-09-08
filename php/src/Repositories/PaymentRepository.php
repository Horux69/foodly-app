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
     * Solo cuenta lo confirmado, igual que
     * PaymentService::getBalanceForOrder: un cobro pendiente o fallido no es
     * plata que haya entrado. La resta contra el total sigue siendo del
     * dominio (Domain\PaymentBalance); aqui solo se suma.
     *
     * @param string[] $orderIds
     * @return array<string, array{paid: int, refunded: int}>
     */
    public function paidTotalsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        // Dos sumas en una pasada. Un cobro cuenta aunque este marcado
        // 'refunded' —ese estado es la etiqueta de "ya se revirtio", y quien
        // lo revierte es su fila de reembolso; contarlo dos veces dejaria el
        // saldo al doble.
        $stmt = $this->pdo->prepare(
            "SELECT order_id,
                    COALESCE(SUM(amount) FILTER (
                        WHERE refund_of_payment_id IS NULL AND status IN ('paid', 'refunded')
                    ), 0) AS paid,
                    COALESCE(SUM(amount) FILTER (
                        WHERE refund_of_payment_id IS NOT NULL AND status = 'paid'
                    ), 0) AS refunded
             FROM payments
             WHERE order_id IN ({$placeholders})
             GROUP BY order_id"
        );
        $stmt->execute(array_values($orderIds));

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[$row['order_id']] = [
                'paid' => Money::fromDecimalString((string) $row['paid']),
                'refunded' => Money::fromDecimalString((string) $row['refunded']),
            ];
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
        ?string $createdBy = null,
        ?string $note = null,
        ?string $refundOfPaymentId = null,
        ?string $cashSessionId = null,
    ): Payment {
        $stmt = $this->pdo->prepare(
            'INSERT INTO payments (
                order_id, method, status, amount, external_reference, idempotency_key, paid_at,
                created_by, note, refund_of_payment_id, cash_session_id
             ) VALUES (
                :order_id, :method, :status, :amount, :external_reference, :idempotency_key, :paid_at,
                :created_by, :note, :refund_of_payment_id, :cash_session_id
             ) RETURNING *'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'method' => $method,
            'status' => $status,
            'amount' => Money::toDecimalString($amountCents),
            'external_reference' => $externalReference,
            'idempotency_key' => $idempotencyKey,
            'paid_at' => $paidAt,
            'created_by' => $createdBy,
            'note' => $note,
            'refund_of_payment_id' => $refundOfPaymentId,
            'cash_session_id' => $cashSessionId,
        ]);
        return Payment::fromRow($stmt->fetch());
    }

    /**
     * Marca un cobro como revertido del todo.
     *
     * Es una etiqueta, no la resta: quien resta es la fila de reembolso. Sirve
     * para que la caja vea de un vistazo que ese cobro ya no cuenta, sin tener
     * que sumar sus devoluciones.
     */
    public function markRefunded(string $paymentId): void
    {
        $stmt = $this->pdo->prepare("UPDATE payments SET status = 'refunded' WHERE id = :id");
        $stmt->execute(['id' => $paymentId]);
    }

    public function get(string $paymentId): ?Payment
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE id = :id');
        $stmt->execute(['id' => $paymentId]);
        $row = $stmt->fetch();
        return $row === false ? null : Payment::fromRow($row);
    }
}
