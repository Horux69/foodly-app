<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\PeriodComparison;
use PHPUnit\Framework\TestCase;

final class PeriodComparisonTest extends TestCase
{
    public function testElAnteriorTieneLosMismosDiasYTerminaJustoAntes(): void
    {
        // Del 1 al 7 son siete dias: el anterior es del 25 al 31.
        $this->assertSame(
            ['2026-08-25', '2026-08-31'],
            PeriodComparison::previousRange('2026-09-01', '2026-09-07')
        );
    }

    public function testUnSoloDiaSeComparaConElAnterior(): void
    {
        $this->assertSame(
            ['2026-08-31', '2026-08-31'],
            PeriodComparison::previousRange('2026-09-01', '2026-09-01')
        );
    }

    public function testTreintaDiasSeComparanConTreinta(): void
    {
        [$desde, $hasta] = PeriodComparison::previousRange('2026-09-01', '2026-09-30');
        $this->assertSame('2026-08-02', $desde);
        $this->assertSame('2026-08-31', $hasta);
    }

    public function testSubir(): void
    {
        $this->assertSame(12.5, PeriodComparison::change(400_000, 450_000));
    }

    public function testBajar(): void
    {
        $this->assertSame(-25.0, PeriodComparison::change(400_000, 300_000));
    }

    public function testSinCambio(): void
    {
        $this->assertSame(0.0, PeriodComparison::change(400_000, 400_000));
    }

    /**
     * Lo que no puede pasar: dividir por cero. "Subio un infinito por ciento"
     * no es una lectura, y la pantalla tiene que decir otra cosa.
     */
    public function testSinBaseNoHayPorcentaje(): void
    {
        $this->assertNull(PeriodComparison::change(0, 450_000));
        $this->assertNull(PeriodComparison::change(0, 0));
    }
}
