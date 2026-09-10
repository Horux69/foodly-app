<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\DeliveryService;
use App\Services\DeliveryServiceError;

/**
 * Zonas de reparto dibujadas en el mapa (F9.3).
 *
 * `delivery_zones.polygon` existe desde el esquema inicial y nadie lo
 * llenaba. Dibujar una zona es configuración opcional: no cambia cómo se
 * elige la zona al tomar un domicilio, que sigue siendo manual.
 */
final class ZonaPoligonoTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa('fast_food');
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;
    }

    private function triangulo(): array
    {
        return [[4.60, -74.08], [4.61, -74.07], [4.62, -74.09]];
    }

    /** Sin dibujarla, una zona nueva no lleva polígono: sigue funcionando como antes de F9.3. */
    public function testUnaZonaNuevaNaceSinPoligono(): void
    {
        $zona = DeliveryService::createZone($this->tenantId, $this->branchId, 'Centro', 5_000_00, 0, null);

        $this->assertNull($zona->polygon);
    }

    public function testSeCreaConUnPoligono(): void
    {
        $zona = DeliveryService::createZone(
            $this->tenantId,
            $this->branchId,
            'Centro',
            5_000_00,
            0,
            null,
            $this->triangulo(),
        );

        $this->assertCount(3, $zona->polygon);
        $this->assertSame(4.60, $zona->polygon[0]['lat']);
        $this->assertSame(-74.08, $zona->polygon[0]['lng']);
    }

    public function testDibujarLaFormaDeUnaZonaExistente(): void
    {
        $zona = DeliveryService::createZone($this->tenantId, $this->branchId, 'Centro', 5_000_00, 0, null);
        $this->assertNull($zona->polygon);

        $dibujada = DeliveryService::updateZonePolygon($this->tenantId, $zona->id, $this->triangulo());

        $this->assertCount(3, $dibujada->polygon);
    }

    public function testRedibujarReemplazaLaFormaAnterior(): void
    {
        $zona = DeliveryService::createZone(
            $this->tenantId,
            $this->branchId,
            'Centro',
            5_000_00,
            0,
            null,
            $this->triangulo(),
        );

        $otra = [[4.70, -74.10], [4.71, -74.11], [4.72, -74.12], [4.73, -74.13]];
        $redibujada = DeliveryService::updateZonePolygon($this->tenantId, $zona->id, $otra);

        $this->assertCount(4, $redibujada->polygon);
        $this->assertSame(4.70, $redibujada->polygon[0]['lat']);
    }

    /** Borrar la forma no apaga la zona: sigue cobrando y pidiendo el mínimo igual. */
    public function testBorrarLaFormaDejaLaZonaFuncionando(): void
    {
        $zona = DeliveryService::createZone(
            $this->tenantId,
            $this->branchId,
            'Centro',
            5_000_00,
            0,
            null,
            $this->triangulo(),
        );

        $sinForma = DeliveryService::updateZonePolygon($this->tenantId, $zona->id, null);

        $this->assertNull($sinForma->polygon);
        $this->assertTrue($sinForma->isActive);
        $this->assertSame(5_000_00, $sinForma->feeCents);
    }

    public function testMenosDeTresPuntosSeRechaza(): void
    {
        $zona = DeliveryService::createZone($this->tenantId, $this->branchId, 'Centro', 5_000_00, 0, null);

        $this->expectException(DeliveryServiceError::class);
        DeliveryService::updateZonePolygon($this->tenantId, $zona->id, [[4.60, -74.08], [4.61, -74.07]]);
    }

    /** Una zona de otra empresa no se puede dibujar, aunque se sepa su id. */
    public function testNoSeDibujaUnaZonaDeOtroTenant(): void
    {
        $zona = DeliveryService::createZone($this->tenantId, $this->branchId, 'Centro', 5_000_00, 0, null);

        [$otroTenant] = $this->nuevaEmpresa('fast_food');
        $this->comoEmpresa($this->tenantId);

        $this->expectException(DeliveryServiceError::class);
        DeliveryService::updateZonePolygon($otroTenant->id, $zona->id, $this->triangulo());
    }
}
