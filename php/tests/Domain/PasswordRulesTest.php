<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\PasswordError;
use App\Domain\PasswordRules;
use PHPUnit\Framework\TestCase;

final class PasswordRulesTest extends TestCase
{
    public function testAceptaUnaContrasenaLarga(): void
    {
        $this->expectNotToPerformAssertions();
        PasswordRules::validate('la del turno de la noche');
    }

    public function testRechazaLaDemasiadoCorta(): void
    {
        $this->expectException(PasswordError::class);
        PasswordRules::validate('corta12');
    }

    /** bcrypt trunca en 72 bytes: mas alla, lo que se escriba no cuenta. */
    public function testRechazaLaQuePasaDeLoQueBcryptMira(): void
    {
        $this->expectException(PasswordError::class);
        PasswordRules::validate(str_repeat('a', 73));
    }

    public function testRechazaSoloEspacios(): void
    {
        $this->expectException(PasswordError::class);
        PasswordRules::validate('          ');
    }

    public function testRechazaRepetirLaActual(): void
    {
        $this->expectException(PasswordError::class);
        $this->expectExceptionMessageMatches('/distinta de la actual/');
        PasswordRules::validate('la misma de siempre', 'la misma de siempre');
    }

    public function testAceptaUnaDistintaDeLaActual(): void
    {
        $this->expectNotToPerformAssertions();
        PasswordRules::validate('la nueva del turno', 'la misma de siempre');
    }
}
