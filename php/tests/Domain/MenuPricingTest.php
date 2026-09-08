<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\MenuPricing;
use PHPUnit\Framework\TestCase;

final class MenuPricingTest extends TestCase
{
    public function testSinOverrideUsaPrecioYDisponibilidadBase(): void
    {
        $result = MenuPricing::resolveEffectiveMenuItem(1_800_000, true);
        $this->assertSame(1_800_000, $result->priceCents);
        $this->assertTrue($result->isAvailable);
    }

    public function testOverrideDePrecioMandaSobreElBase(): void
    {
        $result = MenuPricing::resolveEffectiveMenuItem(1_800_000, true, overridePriceCents: 1_500_000);
        $this->assertSame(1_500_000, $result->priceCents);
        $this->assertTrue($result->isAvailable);
    }

    public function testOverrideDeDisponibilidadNoAfectaElPrecio(): void
    {
        $result = MenuPricing::resolveEffectiveMenuItem(1_800_000, true, overrideIsAvailable: false);
        $this->assertSame(1_800_000, $result->priceCents);
        $this->assertFalse($result->isAvailable);
    }

    public function testOverrideEnFalseExplicitoSeRespeta(): void
    {
        // overrideIsAvailable=false es un valor valido, distinto de "sin override" (null)
        $result = MenuPricing::resolveEffectiveMenuItem(1_800_000, false, overrideIsAvailable: false);
        $this->assertFalse($result->isAvailable);
    }
}
