<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\StationRouting;
use PHPUnit\Framework\TestCase;

final class StationRoutingTest extends TestCase
{
    private const LINEAS = [
        ['menu_item_id' => 'i1', 'category_id' => 'bebidas'],
        ['menu_item_id' => 'i2', 'category_id' => 'carnes'],
        ['menu_item_id' => 'i3', 'category_id' => 'bebidas'],
    ];

    private const RUTEO = ['bebidas' => 'barra', 'carnes' => 'plancha'];
    private const NOMBRES = ['barra' => 'Barra', 'plancha' => 'Plancha'];

    public function testReparteLasLineasEnUnaComandaPorEstacion(): void
    {
        $comandas = StationRouting::split(self::LINEAS, self::RUTEO, self::NOMBRES);

        $this->assertCount(2, $comandas);
        $this->assertSame(['Barra', 'Plancha'], array_column($comandas, 'station_name'));
        $this->assertCount(2, $comandas[0]['lines']);
        $this->assertCount(1, $comandas[1]['lines']);
    }

    /** Sin estaciones configuradas —el caso de casi todos— sale una sola comanda. */
    public function testSinEstacionesTodoVaJunto(): void
    {
        $comandas = StationRouting::split(self::LINEAS, [], []);

        $this->assertCount(1, $comandas);
        $this->assertNull($comandas[0]['station_id']);
        $this->assertCount(3, $comandas[0]['lines']);
    }

    /**
     * Una categoria sin asignar no desaparece: sale en la general. La
     * alternativa seria que un plato no se preparara porque nadie configuro
     * algo, y eso se descubre vendiendo.
     */
    public function testLoQueNoTieneEstacionSaleEnLaGeneral(): void
    {
        $lineas = [...self::LINEAS, ['menu_item_id' => 'i9', 'category_id' => 'postres']];

        $comandas = StationRouting::split($lineas, self::RUTEO, self::NOMBRES);

        $this->assertCount(3, $comandas);
        $ultima = end($comandas);
        $this->assertNull($ultima['station_id']);
        $this->assertSame(StationRouting::SIN_ESTACION, $ultima['station_name']);
        $this->assertSame('i9', $ultima['lines'][0]['menu_item_id']);
    }

    /** Una estacion sin nada que preparar no imprime una hoja en blanco. */
    public function testUnaEstacionSinLineasNoSacaComanda(): void
    {
        $comandas = StationRouting::split(
            [['menu_item_id' => 'i1', 'category_id' => 'bebidas']],
            self::RUTEO,
            self::NOMBRES,
        );

        $this->assertCount(1, $comandas);
        $this->assertSame('Barra', $comandas[0]['station_name']);
    }

    /** Una categoria que apunta a una estacion apagada cae en la general. */
    public function testUnaEstacionQueYaNoExisteNoPierdeElPlato(): void
    {
        $comandas = StationRouting::split(self::LINEAS, self::RUTEO, ['barra' => 'Barra']);

        $this->assertSame(['Barra', StationRouting::SIN_ESTACION], array_column($comandas, 'station_name'));
    }

    public function testUnPedidoVacioNoImprimeNada(): void
    {
        $this->assertSame([], StationRouting::split([], [], []));
    }
}
