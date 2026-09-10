<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\KitchenTickets;
use PHPUnit\Framework\TestCase;

final class KitchenTicketsTest extends TestCase
{
    private const TIEMPOS = ['Entradas', 'Fuertes'];

    private static function linea(string $nombre, int $curso, ?string $fired, ?string $categoria = null): array
    {
        return ['name_snapshot' => $nombre, 'course' => $curso, 'fired_at' => $fired, 'category_id' => $categoria];
    }

    /**
     * Lo que no se marcho no existe para la cocina: si saliera, el postre
     * se prepararia con las entradas, que es lo que los tiempos evitan.
     */
    public function testSoloSaleLoMarchado(): void
    {
        $comandas = KitchenTickets::build(
            [self::linea('Sopa', 1, '2026-01-01 12:00:00'), self::linea('Postre', 2, null)],
            [],
            [],
            self::TIEMPOS,
        );

        $this->assertCount(1, $comandas);
        $this->assertSame('Entradas', $comandas[0]['course_name']);
        $this->assertCount(1, $comandas[0]['lines']);
    }

    public function testUnaComandaPorTiempoYPorEstacion(): void
    {
        $comandas = KitchenTickets::build(
            [
                self::linea('Sopa', 1, 'ya', 'c-cocina'),
                self::linea('Jugo', 1, 'ya', 'c-barra'),
                self::linea('Torta', 2, 'ya', 'c-cocina'),
            ],
            ['c-cocina' => 'e1', 'c-barra' => 'e2'],
            ['e1' => 'Plancha', 'e2' => 'Barra'],
            self::TIEMPOS,
        );

        $this->assertSame(
            [['Entradas', 'Plancha'], ['Entradas', 'Barra'], ['Fuertes', 'Plancha']],
            array_map(static fn ($c) => [$c['course_name'], $c['station_name']], $comandas),
        );
    }

    /** Sin tiempos configurados la comanda no menciona ninguno. */
    public function testSinTiemposNoSeNombraNinguno(): void
    {
        $comandas = KitchenTickets::build([self::linea('Hamburguesa', 1, 'ya')], [], [], []);

        $this->assertCount(1, $comandas);
        $this->assertNull($comandas[0]['course_name']);
        $this->assertSame(1, $comandas[0]['course']);
    }

    public function testLosPendientesLlevanSuNombre(): void
    {
        $pendientes = KitchenTickets::pending(
            [self::linea('Sopa', 1, 'ya'), self::linea('Torta', 2, null)],
            self::TIEMPOS,
        );

        $this->assertSame([['course' => 2, 'name' => 'Fuertes']], $pendientes);
    }
}
