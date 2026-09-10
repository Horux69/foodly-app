<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\SourceCommission;
use App\Domain\SourceCommissionError;
use PHPUnit\Framework\TestCase;

final class SourceCommissionTest extends TestCase
{
    /** Lo que se queda la plataforma sale del total, que es como cobran. */
    public function testLaComisionSaleDelTotal(): void
    {
        $this->assertSame(15_000_00, SourceCommission::feeCents(50_000_00, 30.0));
        $this->assertSame(35_000_00, SourceCommission::netCents(50_000_00, 30.0));
    }

    /** Un origen propio no cuesta nada, y sirve igual para saber de donde viene la venta. */
    public function testSinComisionNoSeDescuentaNada(): void
    {
        $this->assertSame(0, SourceCommission::feeCents(50_000_00, 0.0));
        $this->assertSame(50_000_00, SourceCommission::netCents(50_000_00, 0.0));
    }

    /**
     * Al centavo mas cercano y no truncando: truncar dejaria al restaurante
     * reportando de menos en cada pedido.
     */
    public function testRedondeaAlCentavoMasCercano(): void
    {
        // 12.345 -> 12.35
        $this->assertSame(1235, SourceCommission::feeCents(10_000, 12.345));
    }

    public function testUnPorcentajeImposibleSeRechaza(): void
    {
        SourceCommission::ensureValid(0.0);
        SourceCommission::ensureValid(100.0);

        $this->expectException(SourceCommissionError::class);
        SourceCommission::ensureValid(101.0);
    }

    public function testUnPorcentajeNegativoTampoco(): void
    {
        $this->expectException(SourceCommissionError::class);
        SourceCommission::ensureValid(-1.0);
    }
}
