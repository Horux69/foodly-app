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
}
