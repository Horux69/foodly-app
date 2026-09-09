<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\ChargeError;
use App\Domain\ChargeRules;
use App\Domain\PaymentBalance;
use PHPUnit\Framework\TestCase;

final class ChargeRulesTest extends TestCase
{
    private function saldo(int $total, int ...$cobrados): PaymentBalance
    {
        return PaymentBalance::compute($total, $cobrados);
    }

    public function testCobrarLoQueFalta(): void
    {
        $this->expectNotToPerformAssertions();
        ChargeRules::validate($this->saldo(20_000_00, 5_000_00), 15_000_00);
    }

    public function testCobrarUnaParte(): void
    {
        $this->expectNotToPerformAssertions();
        ChargeRules::validate($this->saldo(20_000_00), 5_000_00);
    }

    public function testRechazaCero(): void
    {
        $this->expectException(ChargeError::class);
        ChargeRules::validate($this->saldo(20_000_00), 0);
    }

    public function testRechazaNegativo(): void
    {
        $this->expectException(ChargeError::class);
        ChargeRules::validate($this->saldo(20_000_00), -5_000_00);
    }

    /**
     * El dedazo que da nombre a la regla: 200000 donde iban 20000. Sin esto
     * el pedido queda saldado con el saldo en negativo y el cajon cuadra de
     * mas sin que nada diga por que.
     */
    public function testRechazaCobrarDeMas(): void
    {
        $this->expectException(ChargeError::class);
        $this->expectExceptionMessageMatches('/solo le faltan 20000.00/');
        ChargeRules::validate($this->saldo(20_000_00), 200_000_00);
    }

    public function testRechazaCobrarSobreUnPedidoYaSaldado(): void
    {
        $this->expectException(ChargeError::class);
        $this->expectExceptionMessageMatches('/ya esta saldado/');
        ChargeRules::validate($this->saldo(20_000_00, 20_000_00), 1_000_00);
    }
}
