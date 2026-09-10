<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\CashMovement;
use App\Domain\CashSessionTotals;
use App\Domain\DrawerMovement;
use PHPUnit\Framework\TestCase;

/**
 * Los movimientos del cajon dentro del cuadre.
 *
 * Van aparte de CashSessionTotalsTest porque prueban lo que se le agrego:
 * que la plata que entra y sale sin ser venta cambie lo que deberia haber.
 */
final class CashSessionTotalsConCajonTest extends TestCase
{
    public function testUnaSalidaBajaLoQueDeberiaHaber(): void
    {
        $totales = CashSessionTotals::compute(
            openingFloatCents: 100_000_00,
            movements: [new CashMovement('cash', 50_000_00)],
            countedCashCents: null,
            cashMethod: 'cash',
            drawer: [new DrawerMovement('out', 20_000_00, 'Pago del gas')],
        );

        $this->assertSame(130_000_00, $totales->expectedCashCents);
        $this->assertSame(20_000_00, $totales->cashOutCents);
        $this->assertSame(0, $totales->cashInCents);
    }

    /**
     * Sin registrar la salida, el arqueo llamaria faltante a una plata que
     * salio con permiso. Es el descuadre que hacia inservible el control.
     */
    public function testRegistrarLaSalidaConvierteUnFaltanteEnUnCuadre(): void
    {
        $sinRegistrar = CashSessionTotals::compute(100_000_00, [], 80_000_00);
        $this->assertSame(-20_000_00, $sinRegistrar->differenceCents);

        $registrada = CashSessionTotals::compute(
            100_000_00,
            [],
            80_000_00,
            'cash',
            [new DrawerMovement('out', 20_000_00, 'Pago del gas')],
        );
        $this->assertSame(0, $registrada->differenceCents);
    }

    /** Lo que entra y lo que sale se reportan aparte: no se netean en una cifra. */
    public function testEntradasYSalidasSeReportanPorSeparado(): void
    {
        $totales = CashSessionTotals::compute(
            0,
            [],
            null,
            'cash',
            [
                new DrawerMovement('in', 30_000_00, 'Prestamo'),
                new DrawerMovement('out', 10_000_00, 'Domiciliario'),
            ],
        );

        $this->assertSame(30_000_00, $totales->cashInCents);
        $this->assertSame(10_000_00, $totales->cashOutCents);
        $this->assertSame(20_000_00, $totales->expectedCashCents);
    }

    /** El cajon no toca lo cobrado: una sangria no es una devolucion. */
    public function testLosMovimientosDelCajonNoTocanLoCobrado(): void
    {
        $totales = CashSessionTotals::compute(
            0,
            [new CashMovement('cash', 50_000_00)],
            null,
            'cash',
            [new DrawerMovement('out', 50_000_00, 'Sangria')],
        );

        $this->assertSame(50_000_00, $totales->netCollectedCents());
        $this->assertSame(0, $totales->expectedCashCents);
    }
}
