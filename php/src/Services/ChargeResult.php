<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Interfaz de cobro.
 *
 * Los metodos presenciales (efectivo, datafono, transferencia) se confirman
 * en el acto: el dinero ya se recibio y el sistema solo lo asienta. Una
 * pasarela real (WhatsApp Pay, PSE) implementa esta misma interfaz y
 * devuelve 'pending' hasta que su webhook confirme, sin que caja ni pedidos
 * cambien.
 */
final class ChargeResult
{
    /** @param string $status 'paid' | 'pending' | 'failed' */
    public function __construct(
        public readonly string $status,
        public readonly ?string $externalReference,
        public readonly ?string $paidAt,
    ) {
    }
}
