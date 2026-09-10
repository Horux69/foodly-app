<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\DiscountError;
use App\Domain\DiscountRules;
use PHPUnit\Framework\TestCase;

final class DiscountRulesTest extends TestCase
{
    private const MOTIVO = '11111111-1111-4111-8111-111111111111';
    private const VENTA = 100_000_00;

    public function testUnDescuentoNormalPasa(): void
    {
        DiscountRules::validate(10_000_00, self::VENTA, self::MOTIVO, null);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Sin motivo el reporte de ajustes lista cifras que nadie puede
     * explicar, que es no tener reporte.
     */
    public function testSinMotivoNoHayDescuento(): void
    {
        $this->expectException(DiscountError::class);
        $this->expectExceptionMessageMatches('/motivo/');
        DiscountRules::validate(10_000_00, self::VENTA, null, null);
    }

    public function testNoSeDescuentaMasDeLoQueSeVende(): void
    {
        $this->expectException(DiscountError::class);
        $this->expectExceptionMessageMatches('/No se puede descontar/');
        DiscountRules::validate(120_000_00, self::VENTA, self::MOTIVO, null);
    }

    /** Regalar la venta entera es válido: el 100% de descuento existe. */
    public function testDescontarLaVentaEnteraSePuede(): void
    {
        DiscountRules::validate(self::VENTA, self::VENTA, self::MOTIVO, null);
        $this->expectNotToPerformAssertions();
    }

    public function testElTopeDelRolSeMideEnPorcentajeDeLaVenta(): void
    {
        // 10% de 100.000 son 10.000: justo cabe.
        DiscountRules::validate(10_000_00, self::VENTA, self::MOTIVO, 10.0);

        $this->expectException(DiscountError::class);
        $this->expectExceptionMessageMatches('/necesita autorizacion/');
        DiscountRules::validate(10_000_01, self::VENTA, self::MOTIVO, 10.0);
    }

    /** Sin tope configurado el rol no tiene límite: es lo que tiene el admin. */
    public function testSinTopeNoHayLimite(): void
    {
        DiscountRules::validate(self::VENTA, self::VENTA, self::MOTIVO, null);
        $this->expectNotToPerformAssertions();
    }

    /** El tope redondea hacia abajo: nunca concede un peso de más. */
    public function testElTopeRedondeaHaciaAbajo(): void
    {
        $this->assertSame(3_333_33, DiscountRules::topeCents(10_000_00, 33.3333));
    }

    public function testCeroNoEsUnDescuento(): void
    {
        $this->expectException(DiscountError::class);
        DiscountRules::validate(0, self::VENTA, self::MOTIVO, null);
    }

    /** La frase la escribe el dominio, para que la bitácora y el ticket digan lo mismo. */
    public function testLaFraseLlevaImportePorcentajeYMotivo(): void
    {
        $this->assertSame(
            'Descuento de 10000.00 (10%) por Cortesia',
            DiscountRules::describe(10_000_00, self::VENTA, 'Cortesia'),
        );
    }
}
