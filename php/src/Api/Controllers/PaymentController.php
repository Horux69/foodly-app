<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Core\Money;
use App\Models\Payment;
use App\Services\PaymentError;
use App\Services\PaymentService;

/** Equivalente PHP de app/api/v1/payments.py. */
final class PaymentController
{
    private static function paymentOut(Payment $p): array
    {
        return [
            'id' => $p->id,
            'method' => $p->method,
            'status' => $p->status,
            'amount' => Money::toDecimalString($p->amountCents),
            'external_reference' => $p->externalReference,
            'paid_at' => $p->paidAt,
        ];
    }

    public static function register(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'payments.register');
        $body = Request::json();

        $amount = Money::fromDecimalString(Request::decimalString($body, 'amount'));
        if ($amount <= 0) {
            throw new ApiException(422, "'amount' debe ser mayor que cero");
        }

        try {
            $payment = PaymentService::registerPayment(
                $ctx->tenantId,
                $params['order_id'],
                Request::string($body, 'method'),
                $amount,
                Request::optionalString($body, 'external_reference'),
                Request::optionalString($body, 'idempotency_key'),
            );
        } catch (PaymentError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::paymentOut($payment), 201);
    }

    public static function list(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');
        try {
            $payments = PaymentService::listPayments($ctx->tenantId, $params['order_id']);
        } catch (PaymentError $e) {
            throw new ApiException(404, $e->getMessage());
        }
        return array_map(self::paymentOut(...), $payments);
    }

    public static function balance(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');
        try {
            $balance = PaymentService::getBalance($ctx->tenantId, $params['order_id']);
        } catch (PaymentError $e) {
            throw new ApiException(404, $e->getMessage());
        }

        return [
            'total' => Money::toDecimalString($balance->totalCents),
            'paid' => Money::toDecimalString($balance->paidCents),
            'pending' => Money::toDecimalString($balance->pendingCents),
            'is_settled' => $balance->isSettled,
        ];
    }
}
