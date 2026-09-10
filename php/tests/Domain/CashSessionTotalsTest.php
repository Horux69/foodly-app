<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\CashMovement;
use App\Domain\CashSessionTotals;
use PHPUnit\Framework\TestCase;

final class CashSessionTotalsTest extends TestCase
{
    /** @return CashMovement[] */
    private function turnoTipico(): array
    {
        return [
            new CashMovement('cash', 5_000_000),
            new CashMovement('card', 3_000_000),
            new CashMovement('cash', 2_000_000),
            new CashMovement('transfer', 1_000_000),
        ];
    }

    public function testUnTurnoSinMovimientosEsperaSoloLaBase(): void
    {
        $totals = CashSessionTotals::compute(10_000_000, []);

        $this->assertSame(10_000_000, $totals->expectedCashCents);
        $this->assertSame([], $totals->netByMethod);
        $this->assertSame(0, $totals->netCollectedCents());
        // Sin conteo todavia no hay diferencia que reportar.
        $this->assertNull($totals->countedCashCents);
        $this->assertNull($totals->differenceCents);
    }

    public function testElEsperadoEnCajonEsLaBaseMasElEfectivo(): void
    {
        $totals = CashSessionTotals::compute(10_000_000, $this->turnoTipico());

        $this->assertSame(17_000_000, $totals->expectedCashCents);
        $this->assertSame(11_000_000, $totals->netCollectedCents());
    }

    public function testLoCobradoConTarjetaNoEntraAlCajon(): void
    {
        $totals = CashSessionTotals::compute(0, [new CashMovement('card', 8_000_000)]);

        // Se reporta por metodo, pero el cajon sigue vacio: nadie metio
        // billetes ahi.
        $this->assertSame(['card' => 8_000_000], $totals->netByMethod);
        $this->assertSame(0, $totals->expectedCashCents);
    }

    public function testCadaMetodoSeReportaPorSeparado(): void
    {
        $totals = CashSessionTotals::compute(0, $this->turnoTipico());

        $this->assertSame(
            ['card' => 3_000_000, 'cash' => 7_000_000, 'transfer' => 1_000_000],
            $totals->netByMethod,
        );
    }

    public function testUnReembolsoEnEfectivoSaleDelCajon(): void
    {
        $totals = CashSessionTotals::compute(10_000_000, [
            new CashMovement('cash', 5_000_000),
            new CashMovement('cash', 2_000_000, isRefund: true),
        ]);

        $this->assertSame(13_000_000, $totals->expectedCashCents);
        $this->assertSame(5_000_000, $totals->chargedCents);
        $this->assertSame(2_000_000, $totals->refundedCents);
        $this->assertSame(3_000_000, $totals->netCollectedCents());
    }

    public function testUnReembolsoConTarjetaNoTocaElCajon(): void
    {
        $totals = CashSessionTotals::compute(10_000_000, [
            new CashMovement('card', 5_000_000),
            new CashMovement('card', 5_000_000, isRefund: true),
        ]);

        $this->assertSame(10_000_000, $totals->expectedCashCents);
        $this->assertSame(['card' => 0], $totals->netByMethod);
    }

    public function testCuandoElConteoCoincideLaDiferenciaEsCero(): void
    {
        $totals = CashSessionTotals::compute(10_000_000, $this->turnoTipico(), 17_000_000);

        $this->assertSame(0, $totals->differenceCents);
    }

    public function testSiSobraPlataLaDiferenciaEsPositiva(): void
    {
        $totals = CashSessionTotals::compute(10_000_000, $this->turnoTipico(), 17_500_000);
        $this->assertSame(500_000, $totals->differenceCents);
    }

    public function testSiFaltaPlataLaDiferenciaEsNegativa(): void
    {
        $totals = CashSessionTotals::compute(10_000_000, $this->turnoTipico(), 16_800_000);
        $this->assertSame(-200_000, $totals->differenceCents);
    }

    public function testCualMetodoEsEfectivoSePuedeCambiar(): void
    {
        // Un restaurante que llame 'efectivo' a su metodo fisico cuadra igual:
        // el dominio no adivina el codigo.
        $totals = CashSessionTotals::compute(
            1_000_000,
            [new CashMovement('efectivo', 4_000_000), new CashMovement('cash', 9_000_000)],
            cashMethod: 'efectivo',
        );

        $this->assertSame(5_000_000, $totals->expectedCashCents);
    }
}
