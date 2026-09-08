<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\PaymentBalance;
use App\Models\Order;
use App\Models\Payment;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;

final class PaymentService
{
    public static function getBalanceForOrder(Order $order): PaymentBalance
    {
        $payments = (new PaymentRepository(Database::app()))->listForOrder($order->id);
        $paid = [];
        foreach ($payments as $payment) {
            if ($payment->status === 'paid') {
                $paid[] = $payment->amountCents;
            }
        }
        return PaymentBalance::compute($order->totalCents, $paid);
    }

    public static function getBalance(string $tenantId, string $orderId): PaymentBalance
    {
        $order = (new OrderRepository(Database::app()))->getById($tenantId, $orderId);
        if ($order === null) {
            throw new PaymentError('Pedido no encontrado');
        }
        return self::getBalanceForOrder($order);
    }

    /** @return Payment[] */
    public static function listPayments(string $tenantId, string $orderId): array
    {
        $order = (new OrderRepository(Database::app()))->getById($tenantId, $orderId);
        if ($order === null) {
            throw new PaymentError('Pedido no encontrado');
        }
        return (new PaymentRepository(Database::app()))->listForOrder($order->id);
    }

    public static function registerPayment(
        string $tenantId,
        string $orderId,
        string $method,
        int $amountCents,
        ?string $externalReference = null,
        ?string $idempotencyKey = null,
    ): Payment {
        $pdo = Database::app();
        $order = (new OrderRepository($pdo))->getById($tenantId, $orderId);
        if ($order === null) {
            throw new PaymentError('Pedido no encontrado');
        }

        $payments = new PaymentRepository($pdo);
        if ($idempotencyKey !== null) {
            $existing = $payments->getByIdempotencyKey($order->id, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        $provider = PaymentProviders::get($method);
        if ($provider === null) {
            throw new PaymentError(
                "Metodo de pago '{$method}' no soportado. Disponibles: "
                . implode(', ', PaymentProviders::availableMethods())
            );
        }

        $result = $provider->charge($amountCents, $externalReference);
        return $payments->create(
            $order->id,
            $method,
            $result->status,
            $amountCents,
            $result->externalReference,
            $idempotencyKey,
            $result->paidAt,
        );
    }
}
