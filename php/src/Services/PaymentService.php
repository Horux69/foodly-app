<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\BillSplit;
use App\Domain\BillSplitError;
use App\Domain\ChargeError;
use App\Domain\ChargeRules;
use App\Domain\PaymentBalance;
use App\Domain\RegisterChoice;
use App\Domain\RegisterChoiceError;
use App\Domain\RefundError;
use App\Domain\RefundRules;
use App\Models\Order;
use App\Models\Payment;
use App\Repositories\CashSessionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;

final class PaymentService
{
    /** unique_violation de Postgres. */
    private const UNIQUE_VIOLATION = '23505';

    /**
     * El turno de caja al que entra este cobro, si hay alguno abierto.
     *
     * Cobrar no exige turno abierto: un restaurante que no lleve caja por
     * turnos tiene que poder seguir vendiendo. Lo que se cobre sin turno
     * queda con cash_session_id nulo y no entra en ningun arqueo — que es
     * exactamente lo que significa.
     *
     * Con varias cajas (F7.4) la eleccion la hace `Domain\RegisterChoice`:
     * con una sola abierta no pregunta nada, con dos exige saber en cual
     * se esta cobrando. Mandar la plata al cajon equivocado no se descubre
     * hasta el arqueo, cuando a una le sobra lo que a la otra le falta.
     */
    private static function openSessionId(string $tenantId, string $branchId, ?string $registerId): ?string
    {
        $abiertas = (new CashSessionRepository(Database::app()))->openForBranch($tenantId, $branchId);

        try {
            return RegisterChoice::sessionFor(
                array_map(static fn ($s) => [
                    'id' => $s->id,
                    'register_id' => $s->registerId,
                    'register_name' => $s->registerName,
                ], $abiertas),
                $registerId,
            );
        } catch (RegisterChoiceError $e) {
            throw new PaymentError($e->getMessage());
        }
    }

    public static function getBalanceForOrder(Order $order): PaymentBalance
    {
        return self::balanceFrom($order, (new PaymentRepository(Database::app()))->listForOrder($order->id));
    }

    /**
     * Separa cobros de reembolsos y deja que el dominio haga la resta.
     *
     * Un cobro cuenta aunque este marcado 'refunded': ese estado es solo la
     * etiqueta de "ya se revirtio", y quien lo revierte es su fila de
     * reembolso. Descontarlo tambien seria restarlo dos veces.
     *
     * @param Payment[] $payments todas las filas del pedido
     */
    private static function balanceFrom(Order $order, array $payments): PaymentBalance
    {
        $paid = [];
        $refunded = [];
        foreach ($payments as $payment) {
            if ($payment->isRefund()) {
                if ($payment->status === 'paid') {
                    $refunded[] = $payment->amountCents;
                }
            } elseif (in_array($payment->status, ['paid', 'refunded'], true)) {
                $paid[] = $payment->amountCents;
            }
        }
        return PaymentBalance::compute($order->totalCents, $paid, $refunded);
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

    /**
     * Como repartir lo que falta de un pedido entre varias personas.
     *
     * Cada parte se cobra despues como un pago parcial cualquiera: dividir
     * una cuenta no cambia el pedido ni sus totales, solo reparte quien pone
     * cuanto. Por eso esto propone importes y no escribe nada.
     */
    public static function splitProposal(string $tenantId, string $orderId, int $parts): BillSplitProposal
    {
        $order = (new OrderRepository(Database::app()))->getById($tenantId, $orderId);
        if ($order === null) {
            throw new PaymentError('Pedido no encontrado');
        }

        $balance = self::getBalanceForOrder($order);

        try {
            $partsCents = BillSplit::equalParts($balance->pendingCents, $parts);
        } catch (BillSplitError $e) {
            throw new PaymentError($e->getMessage());
        }

        // subtotal es la suma de las lineas (con su impuesto ya dentro), asi
        // que lo que sobra del total es exactamente lo que no es producto.
        return new BillSplitProposal(
            $balance->pendingCents,
            $partsCents,
            $order->totalCents - $order->subtotalCents,
        );
    }

    public static function registerPayment(
        string $tenantId,
        string $orderId,
        string $method,
        int $amountCents,
        ?string $externalReference = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
        ?string $registerId = null,
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

        // No se cobra mas de lo que falta. Es el espejo de la regla de los
        // reembolsos, y va despues de la llave de idempotencia a proposito:
        // un reintento del mismo cobro tiene que devolver el que ya existe,
        // no chocar contra un saldo que el propio cobro ya bajo.
        try {
            ChargeRules::validate(self::getBalanceForOrder($order), $amountCents);
        } catch (ChargeError $e) {
            throw new PaymentError($e->getMessage());
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
                createdBy: $createdBy,
                cashSessionId: self::openSessionId($tenantId, $order->branchId, $registerId),
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

    /**
     * Devuelve plata de un cobro.
     *
     * No edita ni borra el cobro original: escribe una fila nueva que apunta
     * a el. La caja necesita saber que entro y que salio, y un UPDATE sobre
     * el cobro borraria justo eso.
     *
     * Sin importe, se devuelve todo lo que quede por devolver de ese cobro,
     * que es el caso comun —un cobro mal hecho se anula entero.
     */
    public static function refundPayment(
        string $tenantId,
        string $orderId,
        string $paymentId,
        ?int $amountCents = null,
        ?string $note = null,
        ?string $createdBy = null,
        ?string $registerId = null,
    ): Payment {
        $pdo = Database::app();
        $order = (new OrderRepository($pdo))->getById($tenantId, $orderId);
        if ($order === null) {
            throw new PaymentError('Pedido no encontrado');
        }

        $payments = new PaymentRepository($pdo);
        $todos = $payments->listForOrder($order->id);

        // Se busca dentro de los pagos del pedido y no por id suelto: asi un
        // payment_id de otro pedido —o de otra empresa— no existe desde aqui.
        $original = null;
        foreach ($todos as $payment) {
            if ($payment->id === $paymentId) {
                $original = $payment;
            }
        }
        if ($original === null) {
            throw new PaymentError('Ese cobro no pertenece a este pedido');
        }
        if ($original->isRefund()) {
            // La cadena tiene un solo eslabon: para deshacer un reembolso se
            // vuelve a cobrar, no se reembolsa el reembolso.
            throw new PaymentError('Un reembolso no se puede reembolsar');
        }
        if ($original->status !== 'paid') {
            throw new PaymentError("Solo se puede reembolsar un cobro confirmado (este esta '{$original->status}')");
        }

        $yaDevuelto = [];
        foreach ($todos as $payment) {
            if ($payment->refundOfPaymentId === $original->id && $payment->status === 'paid') {
                $yaDevuelto[] = $payment->amountCents;
            }
        }

        $amountCents ??= RefundRules::refundableCents($original->amountCents, $yaDevuelto);
        try {
            RefundRules::validate($original->amountCents, $yaDevuelto, $amountCents);
        } catch (RefundError $e) {
            throw new PaymentError($e->getMessage());
        }

        // Se devuelve por el mismo medio por el que entro: quien pago con
        // tarjeta no recibe efectivo de la caja.
        $refund = $payments->create(
            $order->id,
            $original->method,
            'paid',
            $amountCents,
            $original->externalReference,
            null,
            (new \DateTimeImmutable('now'))->format(DATE_ATOM),
            createdBy: $createdBy,
            note: $note,
            refundOfPaymentId: $original->id,
            // La devolucion pertenece al turno en que se hace, no al turno en
            // que se cobro: la plata sale del cajon que este abierto ahora.
            cashSessionId: self::openSessionId($tenantId, $order->branchId, $registerId),
        );

        // Si ya no queda nada por devolver, el cobro original queda marcado.
        if (RefundRules::refundableCents($original->amountCents, [...$yaDevuelto, $amountCents]) === 0) {
            $payments->markRefunded($original->id);
        }

        return $refund;
    }
}
