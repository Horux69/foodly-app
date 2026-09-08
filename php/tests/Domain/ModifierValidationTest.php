<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\ModifierGroupConstraint;
use App\Domain\ModifierValidation;
use App\Domain\ModifierValidationError;
use PHPUnit\Framework\TestCase;

final class ModifierValidationTest extends TestCase
{
    private function requiredOne(): ModifierGroupConstraint
    {
        return new ModifierGroupConstraint('1', 'Tamano', minSelect: 1, maxSelect: 1, isRequired: true);
    }

    private function optionalUpToTwo(): ModifierGroupConstraint
    {
        return new ModifierGroupConstraint('2', 'Adiciones', minSelect: 0, maxSelect: 2, isRequired: false);
    }

    public function testGrupoRequeridoSinSeleccionFalla(): void
    {
        $this->expectException(ModifierValidationError::class);
        ModifierValidation::validateSelection($this->requiredOne(), 0);
    }

    public function testGrupoRequeridoConSeleccionExactaPasa(): void
    {
        ModifierValidation::validateSelection($this->requiredOne(), 1);
        $this->addToAssertionCount(1);
    }

    public function testGrupoOpcionalSinSeleccionPasa(): void
    {
        ModifierValidation::validateSelection($this->optionalUpToTwo(), 0);
        $this->addToAssertionCount(1);
    }

    public function testExcedeElMaximoFalla(): void
    {
        $this->expectException(ModifierValidationError::class);
        ModifierValidation::validateSelection($this->optionalUpToTwo(), 3);
    }

    public function testBajoElMinimoFalla(): void
    {
        $belowMin = new ModifierGroupConstraint('3', 'Salsas', minSelect: 2, maxSelect: 3, isRequired: false);
        $this->expectException(ModifierValidationError::class);
        ModifierValidation::validateSelection($belowMin, 1);
    }
}
