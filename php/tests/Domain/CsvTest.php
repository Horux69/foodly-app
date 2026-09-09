<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Csv;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    private function sinBom(string $csv): string
    {
        return substr($csv, strlen(Csv::BOM));
    }

    public function testEncabezadosYFilas(): void
    {
        $csv = $this->sinBom(Csv::render(['Día', 'Pedidos'], [['2026-09-01', 12], ['2026-09-02', 9]]));
        $this->assertSame("Día;Pedidos\r\n2026-09-01;12\r\n2026-09-02;9\r\n", $csv);
    }

    /** Sin BOM, Excel lee el archivo como latin-1 y los acentos salen rotos. */
    public function testEmpiezaConBom(): void
    {
        $this->assertStringStartsWith(Csv::BOM, Csv::render(['a'], []));
    }

    public function testEntrecomillaLoQueLlevaElSeparador(): void
    {
        $csv = $this->sinBom(Csv::render(['Producto'], [['Hamburguesa; doble']]));
        $this->assertSame("Producto\r\n\"Hamburguesa; doble\"\r\n", $csv);
    }

    public function testDuplicaLasComillasDeDentro(): void
    {
        $csv = $this->sinBom(Csv::render(['Nota'], [['Dijo "sin cebolla"']]));
        $this->assertSame("Nota\r\n\"Dijo \"\"sin cebolla\"\"\"\r\n", $csv);
    }

    public function testEntrecomillaLosSaltosDeLinea(): void
    {
        $csv = $this->sinBom(Csv::render(['Nota'], [["dos\nlineas"]]));
        $this->assertSame("Nota\r\n\"dos\nlineas\"\r\n", $csv);
    }

    public function testElNuloQuedaVacio(): void
    {
        $csv = $this->sinBom(Csv::render(['a', 'b'], [[null, 3]]));
        $this->assertSame("a;b\r\n;3\r\n", $csv);
    }
}
