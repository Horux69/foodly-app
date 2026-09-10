<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\PrintProfile;
use App\Domain\PrintProfileError;
use PHPUnit\Framework\TestCase;

final class PrintProfileTest extends TestCase
{
    /** Sin configurar nada se imprime como siempre: 80 mm y una copia. */
    public function testLosValoresPorDefectoSonLosDeAntes(): void
    {
        $perfiles = PrintProfile::completar([]);

        $this->assertCount(3, $perfiles);
        foreach ($perfiles as $perfil) {
            $this->assertSame(80, $perfil->widthMm);
            $this->assertSame(1, $perfil->copies);
            $this->assertSame(72, $perfil->contentWidthMm());
        }
    }

    public function testCompletaSoloLoQueNadieConfiguro(): void
    {
        $perfiles = PrintProfile::completar(['ticket' => ['width_mm' => 58, 'copies' => 2]]);
        $porDocumento = array_column(
            array_map(static fn (PrintProfile $p) => [$p->document, $p], $perfiles),
            1,
            0,
        );

        $this->assertSame(58, $porDocumento['ticket']->widthMm);
        $this->assertSame(2, $porDocumento['ticket']->copies);
        $this->assertSame(80, $porDocumento['comanda']->widthMm);
    }

    /** Un rollo de 58 imprime 48 mm útiles, no una proporción de 72. */
    public function testElAnchoUtilNoEsUnaProporcion(): void
    {
        $this->assertSame(48, (new PrintProfile('ticket', 58))->contentWidthMm());
        $this->assertSame(72, (new PrintProfile('ticket', 80))->contentWidthMm());
    }

    public function testSoloHayDosAnchos(): void
    {
        $this->expectException(PrintProfileError::class);
        $this->expectExceptionMessageMatches('/58 u 80/');
        PrintProfile::validate('ticket', 76, 1);
    }

    public function testUnDocumentoDesconocidoSeRechaza(): void
    {
        $this->expectException(PrintProfileError::class);
        PrintProfile::validate('poster', 80, 1);
    }

    public function testLasCopiasTienenTope(): void
    {
        PrintProfile::validate('ticket', 80, 3);

        $this->expectException(PrintProfileError::class);
        PrintProfile::validate('ticket', 80, 4);
    }
}
