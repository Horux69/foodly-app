<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\FiscalError;
use App\Domain\FiscalNumbering;
use PHPUnit\Framework\TestCase;

final class FiscalNumberingTest extends TestCase
{
    public function testElPrimeroEsElInicioDelRango(): void
    {
        // current_number arranca en from - 1: nada usado todavia.
        $this->assertSame(1000, FiscalNumbering::next(1000, 2000, 999));
    }

    public function testDespuesVaElSiguiente(): void
    {
        $this->assertSame(1501, FiscalNumbering::next(1000, 2000, 1500));
    }

    /** Numerar fuera del rango autorizado es de las cosas que se castigan. */
    public function testSeAcabaElRango(): void
    {
        $this->expectException(FiscalError::class);
        $this->expectExceptionMessageMatches('/Se acabo el rango/');
        FiscalNumbering::next(1000, 2000, 2000);
    }

    public function testUnaResolucionVencidaNoNumera(): void
    {
        $this->expectException(FiscalError::class);
        $this->expectExceptionMessageMatches('/vencio el 2026-01-31/');
        FiscalNumbering::next(1000, 2000, 1500, '2026-01-31', hoy: '2026-02-01');
    }

    /** El ultimo dia de vigencia todavia sirve. */
    public function testElDiaDelVencimientoTodaviaVale(): void
    {
        $this->assertSame(1501, FiscalNumbering::next(1000, 2000, 1500, '2026-01-31', hoy: '2026-01-31'));
    }

    public function testSinVencimientoNoCaduca(): void
    {
        $this->assertSame(1501, FiscalNumbering::next(1000, 2000, 1500, null, hoy: '2030-01-01'));
    }

    /**
     * Avisar antes de que se acabe: pedir una resolucion nueva a la
     * autoridad toma dias, y quedarse sin numeros es dejar de facturar.
     */
    public function testAvisaAntesDeQuedarseSinNumeros(): void
    {
        $this->assertSame(150, FiscalNumbering::restantes(2000, 1850));
        $this->assertFalse(FiscalNumbering::porAcabarse(2000, 1850));
        $this->assertTrue(FiscalNumbering::porAcabarse(2000, 1900));
    }

    public function testElNumeroCompletoLlevaElPrefijo(): void
    {
        $this->assertSame('POS1000', FiscalNumbering::format('POS', 1000));
        $this->assertSame('1000', FiscalNumbering::format('', 1000));
    }
}
