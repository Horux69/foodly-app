<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\OrderStatus;
use App\Domain\StatusMachine;
use App\Domain\StatusTransition;
use App\Domain\TransitionError;
use PHPUnit\Framework\TestCase;

final class StatusMachineTest extends TestCase
{
    private function machine(): StatusMachine
    {
        $pend = new OrderStatus('1', 'pending', 'new', isInitial: true, isFinal: false);
        $prep = new OrderStatus('2', 'preparing', 'kitchen', isInitial: false, isFinal: false);
        $done = new OrderStatus('3', 'delivered', 'completed', isInitial: false, isFinal: true);

        return new StatusMachine(
            [$pend, $prep, $done],
            [
                new StatusTransition('1', '2', 'orders.advance_kitchen'),
                new StatusTransition('2', '3', null),
            ],
        );
    }

    public function testEstadoInicial(): void
    {
        $this->assertSame('pending', $this->machine()->initial()->code);
    }

    public function testTransicionValidaConPermiso(): void
    {
        $this->machine()->validate('1', '2', ['orders.advance_kitchen']);
        $this->addToAssertionCount(1);
    }

    public function testTransicionSinPermisoFalla(): void
    {
        $this->expectException(TransitionError::class);
        $this->machine()->validate('1', '2', []);
    }

    public function testSaltoDeEstadoNoConfiguradoFalla(): void
    {
        $this->expectException(TransitionError::class);
        $this->machine()->validate('1', '3', ['orders.advance_kitchen']);
    }

    public function testEstadoFinalNoAvanza(): void
    {
        $this->expectException(TransitionError::class);
        $this->machine()->validate('3', '2', ['orders.advance_kitchen']);
    }
}
