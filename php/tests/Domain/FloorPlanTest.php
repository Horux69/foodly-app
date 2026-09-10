<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\FloorPlan;
use App\Domain\FloorPlanError;
use PHPUnit\Framework\TestCase;

final class FloorPlanTest extends TestCase
{
    private static function mesa(string $id, int $x = 0, int $y = 0): array
    {
        return ['id' => $id, 'pos_x' => $x, 'pos_y' => $y];
    }

    /**
     * Todas nacen en 0,0: sin repartirlas, el salon de un restaurante que
     * nunca abrio el modo de edicion seria una pila de mesas en una esquina.
     */
    public function testLasQueNadieColocoSeRepartenEnRejilla(): void
    {
        $mesas = array_map(static fn (int $i) => self::mesa("m{$i}"), range(1, 8));

        $acomodadas = FloorPlan::acomodar($mesas);

        $posiciones = array_map(static fn (array $m) => [$m['pos_x'], $m['pos_y']], $acomodadas);
        $this->assertSame([0, 0], $posiciones[0]);
        $this->assertSame([5, 0], $posiciones[5]);
        // La septima baja de fila: caben seis por fila.
        $this->assertSame([0, 1], $posiciones[6]);
    }

    /** Lo que alguien ya coloco no se toca. */
    public function testLasColocadasSeQuedanDondeEstan(): void
    {
        $acomodadas = FloorPlan::acomodar([self::mesa('m1', 3, 2), self::mesa('m2')]);

        $this->assertSame([3, 2], [$acomodadas[0]['pos_x'], $acomodadas[0]['pos_y']]);
        $this->assertSame([0, 0], [$acomodadas[1]['pos_x'], $acomodadas[1]['pos_y']]);
    }

    /** Y la que se acomoda sola no se pone encima de una ya colocada. */
    public function testNoSeAcomodaEncimaDeUnaColocada(): void
    {
        $acomodadas = FloorPlan::acomodar([self::mesa('fija', 0, 0), self::mesa('libre')]);

        $this->assertNotSame(
            [$acomodadas[0]['pos_x'], $acomodadas[0]['pos_y']],
            [$acomodadas[1]['pos_x'], $acomodadas[1]['pos_y']],
        );
    }

    public function testSinMesasNoHayNadaQueAcomodar(): void
    {
        $this->assertSame([], FloorPlan::acomodar([]));
    }

    public function testUnaFormaDesconocidaSeRechaza(): void
    {
        FloorPlan::validate(0, 0, 'round');

        $this->expectException(FloorPlanError::class);
        $this->expectExceptionMessageMatches('/Forma desconocida/');
        FloorPlan::validate(0, 0, 'triangulo');
    }

    public function testUnaMesaNoSaleDelPlano(): void
    {
        $this->expectException(FloorPlanError::class);
        FloorPlan::validate(-1, 0, 'square');
    }
}
