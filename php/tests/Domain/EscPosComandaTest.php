<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\EscPos;
use App\Domain\EscPosComanda;
use PHPUnit\Framework\TestCase;

final class EscPosComandaTest extends TestCase
{
    private function pedido(array $cambios = []): array
    {
        return array_merge([
            'order_number' => 'NOR-00007',
            'channel' => 'counter',
            'created_at' => '2026-01-15T20:30:00Z',
            'table_code' => null,
            'notes' => null,
            'delivery' => null,
            'items' => [
                ['quantity' => 2, 'name_snapshot' => 'Hamburguesa', 'modifiers' => [], 'components' => [], 'notes' => null],
            ],
        ], $cambios);
    }

    /** Sin estaciones configuradas, una sola hoja con todo el pedido: como antes de F8.1. */
    public function testSinEstacionesUnaSolaHoja(): void
    {
        $hojas = EscPosComanda::build($this->pedido(), 48);

        $this->assertCount(1, $hojas);
        $this->assertStringStartsWith(EscPos::INIT, $hojas[0]);
        $this->assertStringEndsWith(EscPos::CUT, $hojas[0]);
        $this->assertStringContainsString('2x Hamburguesa', $hojas[0]);
    }

    /** Una hoja por estacion: la barra no necesita saber que lleva la plancha. */
    public function testConEstacionesUnaHojaPorCada(): void
    {
        $pedido = $this->pedido([
            'kitchen_tickets' => [
                ['station_id' => 's1', 'station_name' => 'Plancha', 'course' => 1, 'course_name' => null, 'lines' => [
                    ['quantity' => 1, 'name_snapshot' => 'Hamburguesa', 'modifiers' => [], 'components' => [], 'notes' => null],
                ]],
                ['station_id' => 's2', 'station_name' => 'Barra', 'course' => 1, 'course_name' => null, 'lines' => [
                    ['quantity' => 1, 'name_snapshot' => 'Limonada', 'modifiers' => [], 'components' => [], 'notes' => null],
                ]],
            ],
        ]);

        $hojas = EscPosComanda::build($pedido, 48);

        $this->assertCount(2, $hojas);
        $this->assertStringContainsString('PLANCHA', $hojas[0]);
        $this->assertStringContainsString('Hamburguesa', $hojas[0]);
        $this->assertStringNotContainsString('Limonada', $hojas[0]);
        $this->assertStringContainsString('BARRA', $hojas[1]);
        $this->assertStringContainsString('Limonada', $hojas[1]);
    }

    /** Con una sola estacion no hace falta decir cual es: seria ruido en la comanda general. */
    public function testConUnaSolaEstacionNoDiceElNombre(): void
    {
        $pedido = $this->pedido([
            'kitchen_tickets' => [
                ['station_id' => 's1', 'station_name' => 'Plancha', 'course' => 1, 'course_name' => null, 'lines' => $this->pedido()['items']],
            ],
        ]);

        $hojas = EscPosComanda::build($pedido, 48);

        $this->assertCount(1, $hojas);
        $this->assertStringNotContainsString('PLANCHA', $hojas[0]);
    }

    public function testFiltraPorCursoAlReimprimirUnTiempo(): void
    {
        $pedido = $this->pedido([
            'kitchen_tickets' => [
                ['station_id' => null, 'station_name' => null, 'course' => 1, 'course_name' => 'Entradas', 'lines' => [
                    ['quantity' => 1, 'name_snapshot' => 'Sopa', 'modifiers' => [], 'components' => [], 'notes' => null],
                ]],
                ['station_id' => null, 'station_name' => null, 'course' => 2, 'course_name' => 'Fuertes', 'lines' => [
                    ['quantity' => 1, 'name_snapshot' => 'Bandeja', 'modifiers' => [], 'components' => [], 'notes' => null],
                ]],
            ],
        ]);

        $hojas = EscPosComanda::build($pedido, 48, curso: 2);

        $this->assertCount(1, $hojas);
        $this->assertStringContainsString('FUERTES', $hojas[0]);
        $this->assertStringContainsString('Bandeja', $hojas[0]);
    }

    public function testLaReimpresionSaleMarcadaEnGrande(): void
    {
        $hojas = EscPosComanda::build($this->pedido(), 48, reimpresion: true);

        $this->assertStringContainsString('REIMPRESION', $hojas[0]);
        $this->assertStringContainsString('puede estar ya preparado', $hojas[0]);
    }

    public function testLasNotasDeLaLineaSalenComoNota(): void
    {
        $pedido = $this->pedido([
            'items' => [
                ['quantity' => 1, 'name_snapshot' => 'Hamburguesa', 'modifiers' => [], 'components' => [], 'notes' => 'Sin cebolla'],
            ],
        ]);

        $hojas = EscPosComanda::build($pedido, 48);

        $this->assertStringContainsString('NOTA: Sin cebolla', $hojas[0]);
    }

    /** La comanda no lleva precios: a la cocina el dinero no le sirve. */
    public function testNoTraePrecios(): void
    {
        $pedido = $this->pedido([
            'items' => [
                ['quantity' => 1, 'name_snapshot' => 'Hamburguesa', 'modifiers' => [], 'components' => [], 'notes' => null],
            ],
        ]);

        $hojas = EscPosComanda::build($pedido, 48);

        $this->assertStringNotContainsString('COP', $hojas[0]);
    }

    public function testModificadoresComoTextoOComoObjeto(): void
    {
        $pedido = $this->pedido([
            'items' => [
                ['quantity' => 1, 'name_snapshot' => 'Hamburguesa', 'components' => [], 'notes' => null, 'modifiers' => ['Termino: 3/4']],
                ['quantity' => 1, 'name_snapshot' => 'Papas', 'components' => [], 'notes' => null, 'modifiers' => [['name_snapshot' => 'Extra queso']]],
            ],
        ]);

        $hojas = EscPosComanda::build($pedido, 48);

        $this->assertStringContainsString('Termino: 3/4', $hojas[0]);
        $this->assertStringContainsString('Extra queso', $hojas[0]);
    }
}
