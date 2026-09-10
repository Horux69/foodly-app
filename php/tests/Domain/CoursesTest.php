<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\CourseError;
use App\Domain\Courses;
use PHPUnit\Framework\TestCase;

final class CoursesTest extends TestCase
{
    private const TIEMPOS = ['Entradas', 'Fuertes', 'Postres'];

    /** Sin tiempos configurados hay uno solo: el pedido entero. */
    public function testSinTiemposHayUno(): void
    {
        $this->assertSame(1, Courses::cuantos([]));
        Courses::ensureValid(1, []);

        $this->expectException(CourseError::class);
        $this->expectExceptionMessageMatches('/no maneja tiempos/');
        Courses::ensureValid(2, []);
    }

    public function testUnTiempoQueNoExisteSeRechaza(): void
    {
        Courses::ensureValid(3, self::TIEMPOS);

        $this->expectException(CourseError::class);
        $this->expectExceptionMessageMatches('/tiene 3/');
        Courses::ensureValid(4, self::TIEMPOS);
    }

    public function testElNombreSaleDeLaConfiguracion(): void
    {
        $this->assertSame('Fuertes', Courses::label(2, self::TIEMPOS));
    }

    /**
     * Una linea de un tiempo que el restaurante quito sigue diciendo algo
     * cierto: su numero. Es el precio de guardar el numero y no la etiqueta.
     */
    public function testUnTiempoSinNombreSeLlamaPorSuNumero(): void
    {
        $this->assertSame('Tiempo 5', Courses::label(5, self::TIEMPOS));
    }

    /**
     * El primero con lineas, no el numero 1: si el mesero solo tomo fuertes
     * y postres, los fuertes tienen que salir ya.
     */
    public function testMarchaElPrimeroQueTengaLineas(): void
    {
        $this->assertSame(2, Courses::primero([3, 2, 3]));
        $this->assertSame(1, Courses::primero([]));
    }

    /**
     * La ventana es la del mesero a cargo y no la de editar productos: con
     * las entradas servidas —el pedido en 'ready'— es justo cuando se
     * marchan los fuertes.
     */
    public function testSeMarchaMientrasLaCuentaSigaViva(): void
    {
        foreach (['new', 'kitchen', 'ready', 'in_transit'] as $categoria) {
            Courses::ensureMarchable($categoria);
        }
        $this->expectException(CourseError::class);
        $this->expectExceptionMessageMatches('/ya esta cerrada/');
        Courses::ensureMarchable('completed');
    }

    public function testLosPendientesSalenEnOrdenYSinRepetir(): void
    {
        $lineas = [
            ['course' => 1, 'fired' => true],
            ['course' => 3, 'fired' => false],
            ['course' => 2, 'fired' => false],
            ['course' => 3, 'fired' => false],
        ];
        $this->assertSame([2, 3], Courses::pendientes($lineas));
    }

    public function testTodoMarchadoNoDejaPendientes(): void
    {
        $this->assertSame([], Courses::pendientes([['course' => 1, 'fired' => true]]));
    }
}
