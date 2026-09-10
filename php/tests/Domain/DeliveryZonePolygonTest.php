<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\DeliveryZonePolygon;
use App\Domain\DeliveryZonePolygonError;
use PHPUnit\Framework\TestCase;

final class DeliveryZonePolygonTest extends TestCase
{
    /** Menos de tres puntos no encierra ningún área. */
    public function testMenosDeTresPuntosSeRechaza(): void
    {
        $this->expectException(DeliveryZonePolygonError::class);
        $this->expectExceptionMessage('al menos 3 puntos');
        DeliveryZonePolygon::validate([[4.60, -74.08], [4.61, -74.07]]);
    }

    public function testUnTrianguloEsValido(): void
    {
        $puntos = [[4.60, -74.08], [4.61, -74.07], [4.62, -74.09]];
        $this->assertSame(
            [
                ['lat' => 4.60, 'lng' => -74.08],
                ['lat' => 4.61, 'lng' => -74.07],
                ['lat' => 4.62, 'lng' => -74.09],
            ],
            DeliveryZonePolygon::validate($puntos),
        );
    }

    public function testConvierteCadenasNumericasAFloat(): void
    {
        $validado = DeliveryZonePolygon::validate([['4.6', '-74.08'], [4.61, -74.07], [4.62, -74.09]]);
        $this->assertSame(4.6, $validado[0]['lat']);
        $this->assertSame(-74.08, $validado[0]['lng']);
    }

    public function testUnPuntoFueraDeRangoSeRechaza(): void
    {
        $this->expectException(DeliveryZonePolygonError::class);
        $this->expectExceptionMessage('fuera del mapa');
        DeliveryZonePolygon::validate([[100, -74.08], [4.61, -74.07], [4.62, -74.09]]);
    }

    public function testUnPuntoSinDosCoordenadasSeRechaza(): void
    {
        $this->expectException(DeliveryZonePolygonError::class);
        DeliveryZonePolygon::validate([[4.60], [4.61, -74.07], [4.62, -74.09]]);
    }

    public function testUnaCoordenadaNoNumericaSeRechaza(): void
    {
        $this->expectException(DeliveryZonePolygonError::class);
        DeliveryZonePolygon::validate([['norte', -74.08], [4.61, -74.07], [4.62, -74.09]]);
    }

    public function testMasDelMaximoDePuntosSeRechaza(): void
    {
        $puntos = array_fill(0, DeliveryZonePolygon::MAX_PUNTOS + 1, [4.6, -74.08]);
        $this->expectException(DeliveryZonePolygonError::class);
        $this->expectExceptionMessage('máximo es ' . DeliveryZonePolygon::MAX_PUNTOS);
        DeliveryZonePolygon::validate($puntos);
    }

    public function testExactamenteElMaximoEsValido(): void
    {
        $puntos = array_fill(0, DeliveryZonePolygon::MAX_PUNTOS, [4.6, -74.08]);
        $validado = DeliveryZonePolygon::validate($puntos);
        $this->assertCount(DeliveryZonePolygon::MAX_PUNTOS, $validado);
    }
}
