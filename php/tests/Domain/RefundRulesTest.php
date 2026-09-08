<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\RefundError;
use App\Domain\RefundRules;
use PHPUnit\Framework\TestCase;

final class RefundRulesTest extends TestCase
{
    public function testSinReembolsosPreviosSePuedeDevolverTodo(): void
    {
        $this->assertSame(5_000_000, RefundRules::refundableCents(5_000_000, []));
    }

    public function testLoYaDevueltoDescuentaDeLoQueQueda(): void
    {
        $this->assertSame(3_000_000, RefundRules::refundableCents(5_000_000, [2_000_000]));
        $this->assertSame(0, RefundRules::refundableCents(5_000_000, [2_000_000, 3_000_000]));
    }

    public function testUnReembolsoQueCabeNoSeQueja(): void
    {
        RefundRules::validate(5_000_000, [2_000_000], 3_000_000);
        $this->expectNotToPerformAssertions();
    }

    public function testNoSePuedeDevolverMasDeLoQueEntro(): void
    {
        $this->expectException(RefundError::class);
        $this->expectExceptionMessage('solo quedan 30000.00 por devolver');
        RefundRules::validate(5_000_000, [2_000_000], 3_000_001);
    }

    public function testUnCobroYaDevueltoPorCompletoNoAdmiteOtro(): void
    {
        $this->expectException(RefundError::class);
        $this->expectExceptionMessage('ya se reembolso por completo');
        RefundRules::validate(5_000_000, [5_000_000], 1);
    }

    public function testUnReembolsoDeCeroONegativoNoEsUnReembolso(): void
    {
        $this->expectException(RefundError::class);
        RefundRules::validate(5_000_000, [], 0);
    }
}
