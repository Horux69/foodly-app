<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\DeliveryError;
use App\Domain\DeliveryRules;
use App\Domain\DeliveryZoneRules;
use PHPUnit\Framework\TestCase;

final class DeliveryRulesTest extends TestCase
{
    private function zonaNorte(int $minOrderCents = 3_000_000): DeliveryZoneRules
    {
        // 5.000 de tarifa, 30.000 de minimo (en centavos).
        return new DeliveryZoneRules('Norte', 500_000, $minOrderCents, 30);
    }

    public function testAceptaCuandoElSubtotalAlcanzaElMinimo(): void
    {
        DeliveryRules::validateMinimum(3_600_000, $this->zonaNorte());
        $this->expectNotToPerformAssertions();
    }

    public function testElMinimoExactoAlcanza(): void
    {
        DeliveryRules::validateMinimum(3_000_000, $this->zonaNorte());
        $this->expectNotToPerformAssertions();
    }

    public function testRechazaCuandoFaltaParaElMinimo(): void
    {
        $this->expectException(DeliveryError::class);
        DeliveryRules::validateMinimum(1_800_000, $this->zonaNorte());
    }

    public function testElMensajeDiceCuantoFalta(): void
    {
        try {
            DeliveryRules::validateMinimum(1_800_000, $this->zonaNorte());
            $this->fail('Se esperaba un DeliveryError');
        } catch (DeliveryError $e) {
            $this->assertStringContainsString('Norte', $e->getMessage());
            $this->assertStringContainsString('30000.00', $e->getMessage());
            $this->assertStringContainsString('faltan 12000.00', $e->getMessage());
        }
    }

    /**
     * La regla que motiva todo esto: el minimo mide comida, no facturacion.
     * Sumar el envio para llegar al minimo seria hacer trampa, asi que
     * validateMinimum solo recibe el subtotal y no tiene forma de contarlo.
     */
    public function testElEnvioNoCuentaParaAlcanzarElMinimo(): void
    {
        $zona = $this->zonaNorte();
        // 18.000 de comida + 5.000 de envio son 23.000, pero el minimo son
        // 30.000 de comida: sigue faltando.
        $this->assertGreaterThan(0, $zona->feeCents);
        $this->expectException(DeliveryError::class);
        DeliveryRules::validateMinimum(1_800_000, $zona);
    }

    public function testUnaZonaSinMinimoAceptaCualquierPedido(): void
    {
        DeliveryRules::validateMinimum(1, $this->zonaNorte(minOrderCents: 0));
        $this->expectNotToPerformAssertions();
    }
}
