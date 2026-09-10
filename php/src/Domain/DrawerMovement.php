<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Plata que entra o sale del cajon por fuera de una venta.
 *
 * La sangria al llegar al tope, el pago al domiciliario, la compra de
 * emergencia, el prestamo de la caja de al lado. No son cobros —no tienen
 * pedido detras— pero mueven el efectivo, asi que el arqueo tiene que
 * contarlos o declarara un faltante que no lo es.
 *
 * El importe siempre es positivo y el signo lo pone el tipo, igual que en
 * los pagos lo pone `isRefund`: asi el dominio no depende de que alguien se
 * acordara de negarlo.
 */
final class DrawerMovement
{
    public const ENTRADA = 'in';
    public const SALIDA = 'out';
    public const TIPOS = [self::ENTRADA, self::SALIDA];

    public function __construct(
        public readonly string $kind,
        public readonly int $amountCents,
        public readonly string $reason = '',
    ) {
    }

    public function esSalida(): bool
    {
        return $this->kind === self::SALIDA;
    }

    /** Lo que le suma al cajon: negativo si sale. */
    public function efectoCents(): int
    {
        return $this->esSalida() ? -$this->amountCents : $this->amountCents;
    }
}
