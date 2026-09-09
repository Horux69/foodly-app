<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\BranchSchedule;
use App\Domain\ScheduleWindow;
use PHPUnit\Framework\TestCase;

final class BranchScheduleTest extends TestCase
{
    private function allChannels1022(): ScheduleWindow
    {
        return new ScheduleWindow(weekday: 1, opensAt: '10:00:00', closesAt: '22:00:00', channel: null, isActive: true);
    }

    private function deliveryOnly18002300(): ScheduleWindow
    {
        return new ScheduleWindow(weekday: 1, opensAt: '18:00:00', closesAt: '23:00:00', channel: 'delivery', isActive: true);
    }

    private function inactive(): ScheduleWindow
    {
        return new ScheduleWindow(weekday: 1, opensAt: '00:00:00', closesAt: '23:59:00', channel: null, isActive: false);
    }

    /** Viernes de 20:00 a 02:00: cierra antes de la hora a la que abre. */
    private function nocturnaViernes(): ScheduleWindow
    {
        return new ScheduleWindow(weekday: 4, opensAt: '20:00:00', closesAt: '02:00:00', channel: null, isActive: true);
    }

    public function testDentroDeHorarioGeneral(): void
    {
        $this->assertTrue(BranchSchedule::isBranchOpen(1, '12:00:00', 'counter', [$this->allChannels1022()]));
    }

    public function testFueraDeHorario(): void
    {
        $this->assertFalse(BranchSchedule::isBranchOpen(1, '23:00:00', 'counter', [$this->allChannels1022()]));
    }

    public function testDiaDistintoNoAplica(): void
    {
        $this->assertFalse(BranchSchedule::isBranchOpen(2, '12:00:00', 'counter', [$this->allChannels1022()]));
    }

    public function testHorarioEspecificoDeCanalNoAplicaAOtroCanal(): void
    {
        $window = $this->deliveryOnly18002300();
        $this->assertFalse(BranchSchedule::isBranchOpen(1, '19:00:00', 'counter', [$window]));
        $this->assertTrue(BranchSchedule::isBranchOpen(1, '19:00:00', 'delivery', [$window]));
    }

    public function testHorarioInactivoSeIgnora(): void
    {
        $this->assertFalse(BranchSchedule::isBranchOpen(1, '12:00:00', 'counter', [$this->inactive()]));
    }

    public function testVariosHorariosBastaConQueUnoAplique(): void
    {
        $schedules = [$this->deliveryOnly18002300(), $this->allChannels1022()];
        $this->assertTrue(BranchSchedule::isBranchOpen(1, '12:00:00', 'counter', $schedules));
    }

    // ---------- franjas que cruzan la medianoche ----------
    //
    // Sin tratarlas aparte, `opensAt <= $at && $at <= closesAt` no se cumple
    // nunca —ninguna hora es a la vez posterior a las 20:00 y anterior a las
    // 02:00— y el restaurante nocturno quedaria cerrado siempre.

    public function testNocturnaAbiertaLaNocheDelPropioDia(): void
    {
        $this->assertTrue(BranchSchedule::isBranchOpen(4, '23:30:00', 'counter', [$this->nocturnaViernes()]));
    }

    public function testNocturnaAbiertaLaMadrugadaSiguiente(): void
    {
        $this->assertTrue(BranchSchedule::isBranchOpen(5, '01:30:00', 'counter', [$this->nocturnaViernes()]));
    }

    public function testNocturnaCerradaEnMedio(): void
    {
        $this->assertFalse(BranchSchedule::isBranchOpen(4, '15:00:00', 'counter', [$this->nocturnaViernes()]));
        $this->assertFalse(BranchSchedule::isBranchOpen(5, '10:00:00', 'counter', [$this->nocturnaViernes()]));
    }

    /** El dia anterior al lunes es el domingo, no el -1. */
    public function testNocturnaDelDomingoAlcanzaElLunes(): void
    {
        $domingo = new ScheduleWindow(weekday: 6, opensAt: '20:00:00', closesAt: '02:00:00', channel: null, isActive: true);
        $this->assertTrue(BranchSchedule::isBranchOpen(0, '01:00:00', 'counter', [$domingo]));
    }
}
