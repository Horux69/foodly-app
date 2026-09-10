<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\BillSplit;
use App\Domain\BillSplitError;
use PHPUnit\Framework\TestCase;

final class BillSplitTest extends TestCase
{
    public function testUnaCuentaDivisibleSeRepartePareja(): void
    {
        $this->assertSame([1_000_000, 1_000_000, 1_000_000], BillSplit::equalParts(3_000_000, 3));
    }

    public function testUnaSolaParteEsLaCuentaEntera(): void
    {
        $this->assertSame([4_100_000], BillSplit::equalParts(4_100_000, 1));
    }

    public function testElCentavoSueltoLoPaganLasPrimerasPartes(): void
    {
        // 41000.00 entre tres: 13666.67, 13666.67, 13666.66.
        $this->assertSame([1_366_667, 1_366_667, 1_366_666], BillSplit::equalParts(4_100_000, 3));
    }

    /**
     * La razon de ser de esta clase: el centavo no se pierde ni se inventa.
     * Si la suma no diera exacta, el pedido quedaria eternamente sin saldar.
     */
    public function testLasPartesSiempreSumanElTotal(): void
    {
        foreach ([1, 2, 3, 4, 7, 11, 50] as $partes) {
            foreach ([1, 99, 100, 4_100_000, 1_000_001, 999_999_999] as $total) {
                if ($total < $partes) {
                    continue;
                }
                $reparto = BillSplit::equalParts($total, $partes);
                $this->assertCount($partes, $reparto);
                $this->assertSame($total, array_sum($reparto), "{$total} entre {$partes}");
            }
        }
    }

    public function testNingunaParteSeDiferenciaEnMasDeUnCentavo(): void
    {
        $reparto = BillSplit::equalParts(1_000_000, 7);
        $this->assertSame(1, max($reparto) - min($reparto));
    }

    public function testNoSeDivideLoQueYaEstaSaldado(): void
    {
        $this->expectException(BillSplitError::class);
        $this->expectExceptionMessage('No queda nada por cobrar');
        BillSplit::equalParts(0, 2);
    }

    public function testNoSeDivideUnSaldoNegativo(): void
    {
        // Pasa cuando se cobro de mas: el vuelto se maneja fuera del sistema.
        $this->expectException(BillSplitError::class);
        BillSplit::equalParts(-500, 2);
    }

    public function testNoSePuedeCobrarMenosDeUnCentavoPorPersona(): void
    {
        $this->expectException(BillSplitError::class);
        $this->expectExceptionMessage('No alcanza para tantas partes');
        BillSplit::equalParts(2, 3);
    }

    public function testElNumeroDePartesTieneLimites(): void
    {
        $this->expectException(BillSplitError::class);
        BillSplit::equalParts(1_000_000, 0);
    }

    public function testMasDeCincuentaPartesNoEsDividirUnaCuenta(): void
    {
        $this->expectException(BillSplitError::class);
        BillSplit::equalParts(1_000_000, 51);
    }
}
