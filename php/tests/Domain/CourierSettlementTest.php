<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\CourierSettlement;
use App\Domain\CourierSettlementError;
use PHPUnit\Framework\TestCase;

final class CourierSettlementTest extends TestCase
{
    public function testDeberiaTraerLoCobradoEnEfectivo(): void
    {
        $this->assertSame(120_000_00, CourierSettlement::expectedCents(120_000_00, 0));
    }

    /**
     * Un reembolso baja lo que debe traer: esa plata se le devolvio al
     * cliente y exigirsela seria cobrarsela dos veces.
     */
    public function testUnReembolsoBajaLoQueDebeTraer(): void
    {
        $this->assertSame(100_000_00, CourierSettlement::expectedCents(120_000_00, 20_000_00));
    }

    public function testLaDiferenciaEsLoContadoMenosLoEsperado(): void
    {
        $this->assertSame(0, CourierSettlement::differenceCents(100_000_00, 100_000_00));
        $this->assertSame(5_000_00, CourierSettlement::differenceCents(105_000_00, 100_000_00));
        $this->assertSame(-5_000_00, CourierSettlement::differenceCents(95_000_00, 100_000_00));
    }

    public function testNoSeEntregaUnNegativo(): void
    {
        $this->expectException(CourierSettlementError::class);
        CourierSettlement::ensureCounted(-1);
    }

    /**
     * Cuadrar a quien no debe nada cierra la ventana igual: el cobro que
     * entre un minuto despues quedaria del lado ya cuadrado.
     */
    public function testNoSeCuadraAQuienNoDebeNadaYNoEntregaNada(): void
    {
        $this->expectException(CourierSettlementError::class);
        $this->expectExceptionMessageMatches('/no tiene efectivo pendiente/');
        CourierSettlement::ensureHayQueCuadrar(0, 0);
    }

    /** Entregar plata sin nada esperado si vale: alguien cobro por fuera. */
    public function testEntregarSinDeberSeRegistra(): void
    {
        CourierSettlement::ensureHayQueCuadrar(0, 50_000_00);
        $this->assertSame('Sobran 50000.00', CourierSettlement::describe(50_000_00));
    }

    public function testLaDiferenciaSeLee(): void
    {
        $this->assertSame('Cuadra exacto', CourierSettlement::describe(0));
        $this->assertSame('Faltan 3000.00', CourierSettlement::describe(-3_000_00));
    }
}
