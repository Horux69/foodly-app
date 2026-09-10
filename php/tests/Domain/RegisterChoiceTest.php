<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\RegisterChoice;
use App\Domain\RegisterChoiceError;
use PHPUnit\Framework\TestCase;

final class RegisterChoiceTest extends TestCase
{
    /** Cobrar no exige turno abierto: sin ninguno, no hay nada que elegir. */
    public function testSinTurnosAbiertosNoHayNada(): void
    {
        $this->assertNull(RegisterChoice::sessionFor([], null));
        $this->assertNull(RegisterChoice::sessionFor([], 'r1'));
    }

    /** Con una sola caja abierta no se pregunta nada: es el caso de casi todos. */
    public function testConUnaSolaCajaAbiertaNoPreguntaNada(): void
    {
        $abiertas = [['id' => 's1', 'register_id' => null, 'register_name' => null]];
        $this->assertSame('s1', RegisterChoice::sessionFor($abiertas, null));
    }

    public function testConUnaSolaCajaDeVariasConfiguradasTampocoPregunta(): void
    {
        $abiertas = [['id' => 's1', 'register_id' => 'r1', 'register_name' => 'Mostrador']];
        $this->assertSame('s1', RegisterChoice::sessionFor($abiertas, null));
    }

    /** Con dos abiertas y sin decir en cual se esta, no se adivina. */
    public function testConDosAbiertasYSinElegirSeRechaza(): void
    {
        $abiertas = [
            ['id' => 's1', 'register_id' => 'r1', 'register_name' => 'Mostrador'],
            ['id' => 's2', 'register_id' => 'r2', 'register_name' => 'Barra'],
        ];
        $this->expectException(RegisterChoiceError::class);
        $this->expectExceptionMessageMatches('/mas de una caja abierta/');
        RegisterChoice::sessionFor($abiertas, null);
    }

    public function testConDosAbiertasYUnaElegidaVaAEsa(): void
    {
        $abiertas = [
            ['id' => 's1', 'register_id' => 'r1', 'register_name' => 'Mostrador'],
            ['id' => 's2', 'register_id' => 'r2', 'register_name' => 'Barra'],
        ];
        $this->assertSame('s2', RegisterChoice::sessionFor($abiertas, 'r2'));
    }

    /**
     * La caja existe pero no tiene turno abierto: no se cae a la del
     * vecino, que mandaria la plata al cajon equivocado.
     */
    public function testUnaCajaSinTurnoAbiertoNoCaeEnOtra(): void
    {
        $abiertas = [['id' => 's1', 'register_id' => 'r1', 'register_name' => 'Mostrador']];
        $this->assertNull(RegisterChoice::sessionFor($abiertas, 'r2'));
    }
}
