<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\OrderTotals;
use App\Models\DeliveryZone;

/**
 * Lo que costaria el pedido que se esta armando, antes de existir.
 *
 * Ademas de los totales trae el veredicto de la zona: si el subtotal no
 * alcanza el minimo, el aviso viene ya redactado por Domain\DeliveryRules.
 * La pantalla lo muestra tal cual en vez de rehacer la comparacion, que es
 * justo la regla que no debe vivir en dos lugares —y menos en el frontend,
 * donde el mismo error se convierte en "contemos el envio para llegar al
 * minimo".
 */
final class OrderPreview
{
    public function __construct(
        public readonly OrderTotals $totals,
        public readonly ?DeliveryZone $zone = null,
        /** Redactado por el dominio; null si la zona no pone pega. */
        public readonly ?string $minimumWarning = null,
    ) {
    }
}
