<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\DrawerError;
use App\Domain\DrawerRules;
use PHPUnit\Framework\TestCase;

final class DrawerRulesTest extends TestCase
{
    private const EN_CAJA = 500_000_00;

    public function testUnaSalidaNormalPasa(): void
    {
        DrawerRules::validate('out', 20_000_00, 'Pago del gas', self::EN_CAJA);
        $this->expectNotToPerformAssertions();
    }

    /**
     * La regla que importa: del cajon no sale mas de lo que hay. Un cajon en
     * negativo no existe, y el arqueo quedaria en una cifra inexplicable.
     */
    public function testNoSeSacaMasDeLoQueHayEnElCajon(): void
    {
        $this->expectException(DrawerError::class);
        $this->expectExceptionMessageMatches('/en el cajon hay/');
        DrawerRules::validate('out', 600_000_00, 'Sangria', self::EN_CAJA);
    }

    /** Meter plata no tiene tope: nadie se queja de que sobre. */
    public function testUnaEntradaPuedeSuperarLoQueHay(): void
    {
        DrawerRules::validate('in', 900_000_00, 'Prestamo de la otra caja', self::EN_CAJA);
        $this->expectNotToPerformAssertions();
    }

    public function testElMotivoEsObligatorio(): void
    {
        $this->expectException(DrawerError::class);
        $this->expectExceptionMessageMatches('/por que se movio/');
        DrawerRules::validate('out', 10_000_00, '   ', self::EN_CAJA);
    }

    public function testElImporteTieneQueSerPositivo(): void
    {
        $this->expectException(DrawerError::class);
        DrawerRules::validate('in', 0, 'Nada', self::EN_CAJA);
    }

    public function testSoloHayDosTipos(): void
    {
        $this->expectException(DrawerError::class);
        $this->expectExceptionMessageMatches('/Solo/');
        DrawerRules::validate('deposito', 10_000_00, 'Algo', self::EN_CAJA);
    }
}
