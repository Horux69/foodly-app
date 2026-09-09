<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\TenantProfile;
use App\Domain\TenantProfileError;
use PHPUnit\Framework\TestCase;

final class TenantProfileTest extends TestCase
{
    public function testAceptaUnPerfilValido(): void
    {
        $this->expectNotToPerformAssertions();
        TenantProfile::validate('Burger Demo', 'fast_food', 'COP');
    }

    public function testNormalizaLaMoneda(): void
    {
        $this->assertSame('USD', TenantProfile::normalizeCurrency(' usd '));
    }

    public function testRechazaNombreVacio(): void
    {
        $this->expectException(TenantProfileError::class);
        TenantProfile::validate('   ', 'fast_food', 'COP');
    }

    public function testRechazaModeloDeNegocioDesconocido(): void
    {
        $this->expectException(TenantProfileError::class);
        $this->expectExceptionMessageMatches('/food_truck/');
        TenantProfile::validate('Burger Demo', 'food_truck', 'COP');
    }

    public function testRechazaMonedaQueNoSeanTresLetras(): void
    {
        $this->expectException(TenantProfileError::class);
        TenantProfile::validate('Burger Demo', 'fast_food', 'PESOS');
    }

    public function testNoPideConfirmarLoQueNoCambia(): void
    {
        $this->expectNotToPerformAssertions();
        TenantProfile::ensureCurrencyChangeConfirmed('COP', 'COP', false);
    }

    /**
     * La regla que justifica la clase: los pedidos ya emitidos guardan cifras
     * sin moneda, asi que cambiarla no reconvierte nada — solo hace que lo
     * historico se lea con el simbolo equivocado.
     */
    public function testCambiarLaMonedaSinConfirmarFalla(): void
    {
        $this->expectException(TenantProfileError::class);
        $this->expectExceptionMessageMatches('/no reconvierte/');
        TenantProfile::ensureCurrencyChangeConfirmed('COP', 'USD', false);
    }

    public function testCambiarLaMonedaConfirmandoPasa(): void
    {
        $this->expectNotToPerformAssertions();
        TenantProfile::ensureCurrencyChangeConfirmed('COP', 'USD', true);
    }
}
