<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\OrderEditError;
use App\Domain\OrderEditRules;
use PHPUnit\Framework\TestCase;

final class OrderEditRulesTest extends TestCase
{
    public function testSeEditaMientrasElPedidoSigaEnLaCasa(): void
    {
        $this->assertTrue(OrderEditRules::esEditable('new'));
        $this->assertTrue(OrderEditRules::esEditable('kitchen'));
    }

    /**
     * Listo, en camino, entregado o anulado: ya no hay nada que corregir
     * editando la venta.
     */
    public function testNoSeEditaLoQueYaSalio(): void
    {
        foreach (['ready', 'in_transit', 'completed', 'cancelled'] as $categoria) {
            $this->assertFalse(OrderEditRules::esEditable($categoria), $categoria);
            try {
                OrderEditRules::ensureEditable($categoria);
                $this->fail("Deberia haber rechazado {$categoria}");
            } catch (OrderEditError $e) {
                $this->assertStringContainsString('no se puede modificar', $e->getMessage());
            }
        }
    }

    /** La cocina ya lo tiene no impide el cambio: lo avisa. */
    public function testAvisaCuandoLaCocinaYaLoTiene(): void
    {
        $this->assertTrue(OrderEditRules::laCocinaYaLoTiene('kitchen'));
        $this->assertFalse(OrderEditRules::laCocinaYaLoTiene('new'));
    }

    public function testUnPedidoNoSePuedeQuedarSinProductos(): void
    {
        $this->expectException(OrderEditError::class);
        $this->expectExceptionMessageMatches('/anulalo con su motivo/');
        OrderEditRules::ensureQuedanLineas(0);
    }

    public function testConUnaLineaTodaviaEsUnPedido(): void
    {
        OrderEditRules::ensureQuedanLineas(1);
        $this->expectNotToPerformAssertions();
    }

    public function testLaCantidadMinimaEsUno(): void
    {
        $this->expectException(OrderEditError::class);
        OrderEditRules::ensureCantidad(0);
    }

    /**
     * La regla del dinero: quitar productos de un pedido ya cobrado dejaria
     * plata sin venta que la respalde.
     */
    public function testNoSePuedeDejarElPedidoPorDebajoDeLoCobrado(): void
    {
        $this->expectException(OrderEditError::class);
        $this->expectExceptionMessageMatches('/Reembolsa la diferencia/');
        OrderEditRules::ensureCubreLoCobrado(nuevoTotalCents: 10_000_00, netoCobradoCents: 30_000_00);
    }

    public function testQuitarPorEncimaDeLoCobradoSiSePuede(): void
    {
        OrderEditRules::ensureCubreLoCobrado(nuevoTotalCents: 30_000_00, netoCobradoCents: 30_000_00);
        OrderEditRules::ensureCubreLoCobrado(nuevoTotalCents: 40_000_00, netoCobradoCents: 30_000_00);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Un pedido reembolsado entero vuelve a ser editable: el neto es cero.
     * Es el mismo criterio con el que se anula.
     */
    public function testUnPedidoDevueltoEnteroSeVuelveAEditar(): void
    {
        OrderEditRules::ensureCubreLoCobrado(nuevoTotalCents: 0, netoCobradoCents: 0);
        $this->expectNotToPerformAssertions();
    }
}
