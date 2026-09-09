<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\ScheduleError;
use App\Domain\ScheduleRules;
use App\Domain\ScheduleWindow;
use PHPUnit\Framework\TestCase;

final class ScheduleRulesTest extends TestCase
{
    private const CANALES = ['counter', 'table', 'delivery', 'whatsapp', 'app'];

    public function testNormalizaLaHoraCorta(): void
    {
        $this->assertSame('18:00:00', ScheduleRules::normalizeTime('18:00'));
        $this->assertSame('18:30:45', ScheduleRules::normalizeTime('18:30:45'));
    }

    public function testRechazaHoraInvalida(): void
    {
        $this->expectException(ScheduleError::class);
        ScheduleRules::normalizeTime('25:00');
    }

    public function testAceptaUnaFranjaNormal(): void
    {
        $this->expectNotToPerformAssertions();
        ScheduleRules::validate(0, '10:00:00', '22:00:00', null, self::CANALES);
    }

    public function testAceptaUnaFranjaQueCruzaLaMedianoche(): void
    {
        $this->expectNotToPerformAssertions();
        ScheduleRules::validate(4, '20:00:00', '02:00:00', 'delivery', self::CANALES);
    }

    public function testRechazaDiaFueraDeRango(): void
    {
        $this->expectException(ScheduleError::class);
        ScheduleRules::validate(7, '10:00:00', '22:00:00', null, self::CANALES);
    }

    public function testRechazaCanalDesconocido(): void
    {
        $this->expectException(ScheduleError::class);
        ScheduleRules::validate(0, '10:00:00', '22:00:00', 'paloma-mensajera', self::CANALES);
    }

    public function testRechazaAbrirYCerrarALaMismaHora(): void
    {
        $this->expectException(ScheduleError::class);
        $this->expectExceptionMessageMatches('/misma hora/');
        ScheduleRules::validate(0, '10:00:00', '10:00:00', null, self::CANALES);
    }

    // ---------- canales sin cobertura ----------

    private function ventana(?string $channel, bool $isActive = true): ScheduleWindow
    {
        return new ScheduleWindow(0, '10:00:00', '22:00:00', $channel, $isActive);
    }

    public function testSinFranjasNoSeSenalaNingunCanal(): void
    {
        // Sin horarios la sucursal no restringe: no hay nada que avisar.
        $this->assertSame([], ScheduleRules::channelsWithoutWindows([], ['counter', 'delivery']));
    }

    public function testUnaFranjaSinCanalCubreATodos(): void
    {
        $this->assertSame([], ScheduleRules::channelsWithoutWindows([$this->ventana(null)], ['counter', 'delivery']));
    }

    /**
     * El descuido tipico: se configuran las horas del mostrador y se olvida el
     * domicilio. Con horarios ya configurados, ese canal queda cerrado siempre
     * y no da ninguna senal hasta que alguien intenta vender.
     */
    public function testSenalaElCanalQueSeQuedoSinHoras(): void
    {
        $this->assertSame(
            ['delivery'],
            ScheduleRules::channelsWithoutWindows([$this->ventana('counter')], ['counter', 'delivery'])
        );
    }

    public function testUnaFranjaInactivaNoCubre(): void
    {
        $this->assertSame(
            ['delivery'],
            ScheduleRules::channelsWithoutWindows(
                [$this->ventana('counter'), $this->ventana('delivery', isActive: false)],
                ['counter', 'delivery']
            )
        );
    }

    public function testTodasInactivasEsComoNoTenerHorarios(): void
    {
        $this->assertSame(
            [],
            ScheduleRules::channelsWithoutWindows([$this->ventana('counter', isActive: false)], ['counter', 'delivery'])
        );
    }
}
