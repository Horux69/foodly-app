<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\EscPos;
use PHPUnit\Framework\TestCase;

final class EscPosTest extends TestCase
{
    public function testAnchoDeColumnasSegunElRollo(): void
    {
        $this->assertSame(32, EscPos::anchoDeColumnas(58));
        $this->assertSame(48, EscPos::anchoDeColumnas(80));
        // Cualquier otro valor cae al ancho grande: es el que ya usa
        // Domain\PrintProfile como valor por defecto.
        $this->assertSame(48, EscPos::anchoDeColumnas(70));
    }

    public function testAlign(): void
    {
        $this->assertSame("\x1B\x61\x00", EscPos::align('left'));
        $this->assertSame("\x1B\x61\x01", EscPos::align('center'));
        $this->assertSame("\x1B\x61\x02", EscPos::align('right'));
    }

    public function testBold(): void
    {
        $this->assertSame("\x1B\x45\x01", EscPos::bold(true));
        $this->assertSame("\x1B\x45\x00", EscPos::bold(false));
    }

    /** Sin conocer la impresora real, se pierde la tilde antes que mandar bytes que no sabe interpretar. */
    public function testAsciiQuitaTildes(): void
    {
        $this->assertSame('Preparacion', EscPos::ascii('Preparación'));
        $this->assertSame('Nino', EscPos::ascii('Niño'));
        $this->assertSame('Bogota', EscPos::ascii('Bogotá'));
    }

    public function testLineaTerminaEnSaltoDeLinea(): void
    {
        $this->assertSame("Hola\n", EscPos::linea('Hola'));
        $this->assertSame("\n", EscPos::linea());
    }

    public function testSeparador(): void
    {
        $this->assertSame("----\n", EscPos::separador(4));
        $this->assertSame("====\n", EscPos::separador(4, '='));
    }

    public function testFilaReparteElEspacioEntreLasDosColumnas(): void
    {
        $this->assertSame("A     B\n", EscPos::fila('A', 'B', 7));
    }

    /** Si no caben las dos columnas, la derecha baja a su propia linea en vez de cortarse. */
    public function testFilaSinEspacioBajaLaColumnaDerecha(): void
    {
        $resultado = EscPos::fila('Un texto muy largo', 'COP 10.000', 10);
        $this->assertSame("Un texto muy largo\nCOP 10.000\n", $resultado);
    }

    public function testNombreCanal(): void
    {
        $this->assertSame('Mostrador', EscPos::nombreCanal('counter'));
        $this->assertSame('Domicilio', EscPos::nombreCanal('delivery'));
        $this->assertSame('otro', EscPos::nombreCanal('otro'));
    }

    public function testFechaHoraEnLaZonaDeLaSucursal(): void
    {
        // 2026-01-15T20:30:00Z son las 15:30 en Bogota (UTC-5).
        $this->assertSame('15/01 15:30', EscPos::fechaHora('2026-01-15T20:30:00Z', 'America/Bogota'));
    }

    public function testFechaHoraConIsoInvalidoNoRevienta(): void
    {
        $this->assertSame('no-es-una-fecha', EscPos::fechaHora('no-es-una-fecha', 'America/Bogota'));
    }
}
