<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\ServerAssignment;
use App\Domain\ServerAssignmentError;
use PHPUnit\Framework\TestCase;

final class ServerAssignmentTest extends TestCase
{
    /**
     * La ventana es mas ancha que la de editar los productos: un cambio de
     * turno con el plato ya en camino es de todos los dias.
     */
    public function testSeCambiaMientrasLaCuentaSigaViva(): void
    {
        foreach (['new', 'kitchen', 'ready', 'in_transit'] as $categoria) {
            $this->assertTrue(ServerAssignment::esAsignable($categoria), $categoria);
        }
    }

    public function testEntregadaYaNoSeCambia(): void
    {
        $this->assertFalse(ServerAssignment::esAsignable('completed'));
        $this->expectException(ServerAssignmentError::class);
        $this->expectExceptionMessageMatches('/ya esta cerrada/');
        ServerAssignment::ensureAsignable('completed');
    }

    public function testAnuladaTampoco(): void
    {
        $this->assertFalse(ServerAssignment::esAsignable('cancelled'));
    }

    /**
     * La nota lleva el nombre anterior: quien revisa el reparto de la
     * propina necesita saber de quien era la mesa antes, no solo de quien es
     * ahora.
     */
    public function testLaNotaDiceDeDondeVieneYADondeVa(): void
    {
        $this->assertSame('Mesero: Ana', ServerAssignment::nota(null, 'Ana'));
        $this->assertSame('Mesero cambiado de Ana a Luis', ServerAssignment::nota('Ana', 'Luis'));
        $this->assertSame('Se quito a Ana de la cuenta', ServerAssignment::nota('Ana', null));
        $this->assertSame('Cuenta sin mesero', ServerAssignment::nota(null, null));
    }

    /** Reasignar al mismo no cuenta la historia dos veces. */
    public function testAlMismoNoDiceQueCambio(): void
    {
        $this->assertSame('Mesero: Ana', ServerAssignment::nota('Ana', 'Ana'));
    }
}
