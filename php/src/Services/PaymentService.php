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
    /** unique_violation de Postgres. */
    private const UNIQUE_VIOLATION = '23505';

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

        // La lectura de arriba atrapa el reintento que llega despues; esta
        // parte atrapa el que llega *a la vez*, cuando ambas peticiones
        // leyeron antes de que ninguna escribiera. El indice unico de la
        // migracion 004 deja pasar solo una y aqui se devuelve ese cobro.
        //
        // Con SAVEPOINT porque en Postgres un INSERT fallido aborta toda la
        // transaccion, y public/index.php abre una por peticion: sin el, la
        // consulta siguiente moriria con "current transaction is aborted".
        $pdo->exec('SAVEPOINT registrar_pago');
        try {
            $payment = $payments->create(
                $order->id,
                $method,
                $result->status,
                $amountCents,
                $result->externalReference,
                $idempotencyKey,
                $result->paidAt,
            );
        } catch (\PDOException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT registrar_pago');
            $existing = $idempotencyKey !== null && $e->getCode() === self::UNIQUE_VIOLATION
                ? $payments->getByIdempotencyKey($order->id, $idempotencyKey)
                : null;
            if ($existing === null) {
                throw $e;
            }
            return $existing;
        }
        $pdo->exec('RELEASE SAVEPOINT registrar_pago');

        return $payment;
    }
}
