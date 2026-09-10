<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\DeliveryPromise;
use PHPUnit\Framework\TestCase;

final class DeliveryPromiseTest extends TestCase
{
    private const AHORA = '2026-09-10 19:00:00';

    /** El que no promete nada no incumple. */
    public function testSinPromesaNoHayEstado(): void
    {
        $this->assertSame(DeliveryPromise::SIN_PROMESA, DeliveryPromise::estado(null, null, self::AHORA));
        $this->assertSame(0, DeliveryPromise::retrasoMinutos(null, null, self::AHORA));
    }

    public function testEnCaminoYConTiempoDeSobraVaATiempo(): void
    {
        $this->assertSame(
            DeliveryPromise::A_TIEMPO,
            DeliveryPromise::estado('2026-09-10 19:45:00', null, self::AHORA),
        );
    }

    /**
     * El aviso llega antes del incumplimiento: cuando falta poco todavia se
     * puede apurar la cocina o llamar al cliente.
     */
    public function testCuandoFaltaPocoAvisa(): void
    {
        $this->assertSame(
            DeliveryPromise::EN_RIESGO,
            DeliveryPromise::estado('2026-09-10 19:08:00', null, self::AHORA),
        );
    }

    public function testPasadaLaHoraEstaTarde(): void
    {
        $this->assertSame(
            DeliveryPromise::TARDE,
            DeliveryPromise::estado('2026-09-10 18:45:00', null, self::AHORA),
        );
        $this->assertSame(15, DeliveryPromise::retrasoMinutos('2026-09-10 18:45:00', null, self::AHORA));
    }

    /**
     * Un pedido entregado se juzga contra su entrega y no contra el reloj:
     * si no, todo lo de ayer figuraria tarde para siempre.
     */
    public function testLoEntregadoSeJuzgaContraSuEntrega(): void
    {
        $this->assertSame(
            DeliveryPromise::A_TIEMPO,
            DeliveryPromise::estado('2026-09-10 12:30:00', '2026-09-10 12:25:00', self::AHORA),
        );
        $this->assertSame(
            DeliveryPromise::TARDE,
            DeliveryPromise::estado('2026-09-10 12:30:00', '2026-09-10 12:50:00', self::AHORA),
        );
        $this->assertSame(
            20,
            DeliveryPromise::retrasoMinutos('2026-09-10 12:30:00', '2026-09-10 12:50:00', self::AHORA),
        );
    }

    /** Entregado antes de tiempo no es un retraso negativo. */
    public function testAntesDeTiempoNoEsRetraso(): void
    {
        $this->assertSame(
            0,
            DeliveryPromise::retrasoMinutos('2026-09-10 12:30:00', '2026-09-10 12:00:00', self::AHORA),
        );
    }
}
