<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\PaymentBalance;
use PHPUnit\Framework\TestCase;

final class PaymentBalanceTest extends TestCase
{
    public function testSinPagosQuedaTodoPendiente(): void
    {
        $balance = PaymentBalance::compute(6_200_000, []);
        $this->assertSame(0, $balance->paidCents);
        $this->assertSame(6_200_000, $balance->pendingCents);
        $this->assertFalse($balance->isSettled);
    }

    public function testPagoParcialNoSalda(): void
    {
        $balance = PaymentBalance::compute(6_200_000, [2_000_000]);
        $this->assertSame(4_200_000, $balance->pendingCents);
        $this->assertFalse($balance->isSettled);
    }

    public function testPagosDivididosQueSumanElTotalSaldan(): void
    {
        $balance = PaymentBalance::compute(6_200_000, [2_000_000, 3_000_000, 1_200_000]);
        $this->assertSame(0, $balance->pendingCents);
        $this->assertTrue($balance->isSettled);
    }

    public function testPagoDeMasQuedaSaldadoConPendienteNegativo(): void
    {
        // Caso real de caja: se recibe de mas y el vuelto se maneja fuera del sistema.
        $balance = PaymentBalance::compute(6_200_000, [7_000_000]);
        $this->assertSame(-800_000, $balance->pendingCents);
        $this->assertTrue($balance->isSettled);
    }

    public function testUnReembolsoDevuelveElPedidoAPendiente(): void
    {
        $balance = PaymentBalance::compute(6_200_000, [6_200_000], [6_200_000]);

        // Lo cobrado y lo devuelto se ven por separado a proposito: un pedido
        // cobrado y reembolsado no es lo mismo que uno que nunca se cobro.
        $this->assertSame(6_200_000, $balance->paidCents);
        $this->assertSame(6_200_000, $balance->refundedCents);
        $this->assertSame(0, $balance->netPaidCents);
        $this->assertSame(6_200_000, $balance->pendingCents);
        $this->assertFalse($balance->isSettled);
    }

    public function testUnReembolsoParcialDejaElRestoCobrado(): void
    {
        $balance = PaymentBalance::compute(6_200_000, [6_200_000], [2_000_000]);
        $this->assertSame(4_200_000, $balance->netPaidCents);
        $this->assertSame(2_000_000, $balance->pendingCents);
        $this->assertFalse($balance->isSettled);
    }

    public function testVariosReembolsosSeSuman(): void
    {
        $balance = PaymentBalance::compute(6_200_000, [4_000_000, 2_200_000], [1_000_000, 1_200_000]);
        $this->assertSame(2_200_000, $balance->refundedCents);
        $this->assertSame(4_000_000, $balance->netPaidCents);
        $this->assertSame(2_200_000, $balance->pendingCents);
    }

    public function testSinReembolsosElNetoEsLoCobrado(): void
    {
        $balance = PaymentBalance::compute(6_200_000, [2_000_000]);
        $this->assertSame(0, $balance->refundedCents);
        $this->assertSame($balance->paidCents, $balance->netPaidCents);
    }
}
