<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\ModifierGroupError;
use App\Domain\ModifierGroupRules;
use App\Domain\ModifierGroupConstraint;
use App\Domain\ModifierValidation;
use App\Domain\ModifierValidationError;
use PHPUnit\Framework\TestCase;

final class ModifierGroupRulesTest extends TestCase
{
    public function testAceptaUnGrupoObligatorioDeUnaSola(): void
    {
        $this->expectNotToPerformAssertions();
        ModifierGroupRules::validate('Termino de la carne', 1, 1, true);
    }

    public function testAceptaUnGrupoOpcionalDeVarias(): void
    {
        $this->expectNotToPerformAssertions();
        ModifierGroupRules::validate('Adiciones', 0, 5, false);
    }

    public function testRechazaNombreVacio(): void
    {
        $this->expectException(ModifierGroupError::class);
        ModifierGroupRules::validate('   ', 0, 1, false);
    }

    public function testRechazaMinimoMayorQueMaximo(): void
    {
        $this->expectException(ModifierGroupError::class);
        ModifierGroupRules::validate('Salsas', 3, 2, false);
    }

    /**
     * El caso que la base deja pasar: `CHECK (max_select >= min_select)` acepta
     * 0 y 0. Se prueba con un grupo NO obligatorio a proposito, porque siendo
     * obligatorio tambien saltaria la regla del minimo y esta prueba pasaria
     * sin comprobar nada.
     */
    public function testRechazaMaximoCero(): void
    {
        $this->expectException(ModifierGroupError::class);
        $this->expectExceptionMessageMatches('/El maximo debe ser al menos 1/');
        ModifierGroupRules::validate('Salsas', 0, 0, false);
    }

    public function testUnGrupoObligatorioConMaximoCeroNoSePodriaCumplirNunca(): void
    {
        $imposible = new ModifierGroupConstraint('g1', 'Salsas', 0, 0, true);

        // Sin elegir nada falla por obligatorio; eligiendo una, por el maximo.
        // No hay cantidad que pase, asi que ningun pedido con ese producto se
        // podria crear — y el sintoma saldria en el mostrador.
        $this->expectException(ModifierValidationError::class);
        ModifierValidation::validateSelection($imposible, 0);
    }

    public function testUnGrupoObligatorioConMaximoCeroTampocoPasaConUna(): void
    {
        $imposible = new ModifierGroupConstraint('g1', 'Salsas', 0, 0, true);
        $this->expectException(ModifierValidationError::class);
        ModifierValidation::validateSelection($imposible, 1);
    }

    public function testRechazaObligatorioConMinimoCero(): void
    {
        $this->expectException(ModifierGroupError::class);
        $this->expectExceptionMessageMatches('/minimo de al menos 1/');
        ModifierGroupRules::validate('Salsas', 0, 2, true);
    }

    public function testDescribeLaReglaComoSeLee(): void
    {
        $this->assertSame('Elige 1', ModifierGroupRules::describe(1, 1, true));
        $this->assertSame('Opcional, hasta 3', ModifierGroupRules::describe(0, 3, false));
        $this->assertSame('Obligatorio: elige entre 1 y 3', ModifierGroupRules::describe(1, 3, true));
        $this->assertSame('Elige exactamente 2', ModifierGroupRules::describe(2, 2, false));
    }
}
