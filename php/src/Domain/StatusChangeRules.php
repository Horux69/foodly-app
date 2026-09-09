<?php

declare(strict_types=1);

namespace App\Domain;

use App\Core\Money;

/**
 * Que exige entrar a un estado, mas alla de que la transicion este
 * configurada.
 *
 * La maquina de estados (StatusMachine) responde "¿este tenant permite ir de
 * aqui a alla, y con que permiso?". Esto responde otra pregunta: "¿el dinero
 * del pedido permite este movimiento?". Son reglas de la plataforma y no de
 * cada restaurante, por eso no viven en la configuracion.
 *
 * Se razona por categoria y nunca por el codigo del estado: un restaurante
 * llama 'Entregado' a lo que otro llama 'Servido', y ambos son 'completed'.
 */
final class StatusChangeRules
{
    private function __construct()
    {
    }

    public static function ensureCanEnter(string $category, PaymentBalance $balance, ?string $note): void
    {
        if ($category === 'completed') {
            self::ensureSettled($balance);
        }
        if ($category === 'cancelled') {
            self::ensureNothingHeld($balance);
            self::ensureReason($note);
        }
    }

    /** Un pedido no se da por vendido sin estar cobrado. */
    private static function ensureSettled(PaymentBalance $balance): void
    {
        if ($balance->isSettled) {
            return;
        }
        throw new StatusChangeError(
            'El pedido no esta saldado: faltan ' . Money::toDecimalString($balance->pendingCents)
        );
    }

    /**
     * Un pedido con plata encima no se anula: primero se devuelve.
     *
     * Cancelar dejaria un cobro sin venta que lo respalde —la caja cuadraria
     * de mas y el cliente se quedaria sin su plata y sin su pedido—, y el
     * sistema no tendria como distinguir eso de un descuadre. El reembolso es
     * el paso que hace que la plata salga de verdad y quede registrado que
     * salio.
     *
     * Se mira el neto, no lo cobrado: un pedido cuyos cobros ya se
     * reembolsaron enteros no tiene nada encima y se puede anular.
     */
    private static function ensureNothingHeld(PaymentBalance $balance): void
    {
        if ($balance->netPaidCents <= 0) {
            return;
        }
        throw new StatusChangeError(sprintf(
            'El pedido tiene %s cobrados: reembolsa antes de anularlo',
            Money::toDecimalString($balance->netPaidCents),
        ));
    }

    /**
     * Anular exige decir por que.
     *
     * Es lo unico que convierte una anulacion en algo auditable: el reporte de
     * cierre lista quien anulo y cuanto, y sin motivo esa lista no responde la
     * pregunta que se le hace.
     */
    private static function ensureReason(?string $note): void
    {
        if (trim((string) $note) === '') {
            throw new StatusChangeError('Para anular un pedido hay que decir el motivo');
        }
    }
}
