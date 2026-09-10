<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\OrderTracking;
use PHPUnit\Framework\TestCase;

final class OrderTrackingTest extends TestCase
{
    /** Un pedido que se recoge no pasa por "en camino". */
    public function testElRecorridoDependeDeSiViaja(): void
    {
        $this->assertSame(
            ['Recibido', 'En preparación', 'Listo', 'En camino', 'Entregado'],
            OrderTracking::pasos(true),
        );
        $this->assertSame(
            ['Recibido', 'En preparación', 'Listo', 'Entregado'],
            OrderTracking::pasos(false),
        );
    }

    public function testCadaCategoriaCaeEnSuPaso(): void
    {
        $this->assertSame(0, OrderTracking::paso('new', true));
        $this->assertSame(1, OrderTracking::paso('kitchen', true));
        $this->assertSame(3, OrderTracking::paso('in_transit', true));
        $this->assertSame(4, OrderTracking::paso('completed', true));
    }

    /** Sin viaje, "entregado" es el cuarto paso y no el quinto. */
    public function testSinViajeElFinalSeCorre(): void
    {
        $this->assertSame(3, OrderTracking::paso('completed', false));
    }

    /**
     * Anulado no es el ultimo paso sino la interrupcion del camino:
     * pintarlo al final diria que el pedido se completo.
     */
    public function testAnuladoNoEsUnPaso(): void
    {
        $this->assertNull(OrderTracking::paso('cancelled', true));
        $this->assertTrue(OrderTracking::anulado('cancelled'));
        $this->assertFalse(OrderTracking::anulado('completed'));
    }

    /** Al cliente le sirve el nombre de pila; el apellido del empleado no es suyo. */
    public function testDelRepartidorSoloElNombreDePila(): void
    {
        $this->assertSame('Luis', OrderTracking::nombreCorto('Luis Perez Gomez'));
        $this->assertNull(OrderTracking::nombreCorto(null));
        $this->assertNull(OrderTracking::nombreCorto('   '));
    }
}
