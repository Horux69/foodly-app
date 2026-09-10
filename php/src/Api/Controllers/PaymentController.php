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
            // Con esto la pantalla distingue un cobro de una devolucion sin
            // tener que adivinarlo por el signo, que en la base no existe.
            'refund_of_payment_id' => $p->refundOfPaymentId,
            'note' => $p->note,
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
                $ctx->userId,
                // En que caja se esta cobrando (F7.4): con una sola caja
                // abierta no hace falta decirlo.
                Request::optionalUuid($body, 'register_id'),
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

        return OrderController::balanceOut($balance);
    }

    /**
     * Propuesta de reparto de la cuenta.
     *
     * El calculo esta en Domain\BillSplit y no en el navegador por el
     * centavo suelto: 41000 entre tres no da redondo, y dos divisiones con
     * `toFixed(2)` dejarian el pedido con un centavo pendiente para siempre.
     */
    public static function split(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'payments.register');
        $parts = Request::queryInt('parts', default: 2, min: 1, max: 50);

        try {
            $proposal = PaymentService::splitProposal($ctx->tenantId, $params['order_id'], $parts);
        } catch (PaymentError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return [
            'pending' => Money::toDecimalString($proposal->pendingCents),
            'parts' => array_map(Money::toDecimalString(...), $proposal->partsCents),
            // Envio menos descuento mas propina: no esta en ninguna linea,
            // asi que al repartir por productos hay que decirlo.
            'non_item_total' => Money::toDecimalString($proposal->nonItemCents),
        ];
    }

    /**
     * Reembolsa un cobro.
     *
     * Bajo 'payments.refund', el permiso que estaba en el catalogo desde el
     * principio sin ningun endpoint que lo usara. Sin `amount` devuelve todo
     * lo que quede de ese cobro, que es el caso comun.
     */
    public static function refund(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'payments.refund');
        $body = Request::json();

        $amount = array_key_exists('amount', $body) && $body['amount'] !== null
            ? Money::fromDecimalString(Request::decimalString($body, 'amount'))
            : null;

        try {
            $refund = PaymentService::refundPayment(
                $ctx->tenantId,
                $params['order_id'],
                $params['payment_id'],
                $amount,
                Request::optionalString($body, 'note'),
                $ctx->userId,
                Request::optionalUuid($body, 'register_id'),
            );
        } catch (PaymentError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::paymentOut($refund), 201);
    }
}
