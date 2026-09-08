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
}
