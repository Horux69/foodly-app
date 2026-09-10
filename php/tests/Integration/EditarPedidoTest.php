<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Services\MenuService;
use App\Services\OrderError;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\PaymentService;

/**
 * Modificar un pedido abierto (F4.0).
 *
 * Es la operacion mas comun de un mostrador y la que mas cerca pasa del
 * dinero: cada cambio recalcula el total de una venta que quiza ya tiene
 * cobros encima. Lo que se prueba aqui es justo lo que no se ve en el
 * dominio: que el precio viejo no se mueva cuando el del menu cambia, que la
 * composicion de un combo se reescale, y que el saldo quede coherente.
 */
final class EditarPedidoTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $hamburguesa;
    private string $gaseosa;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa();
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->hamburguesa = MenuService::createItem($this->tenantId, $categoria->id, 'Hamburguesa', 20_000_00)->id;
        $this->gaseosa = MenuService::createItem($this->tenantId, $categoria->id, 'Gaseosa', 5_000_00)->id;
    }

    private function pedido(int $cantidad = 1): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->hamburguesa, $cantidad, [])],
        );
    }

    public function testAgregarUnaLineaSubeElTotal(): void
    {
        $pedido = $this->pedido();

        $editado = OrderService::addLines(
            $this->tenantId,
            $pedido->id,
            [new OrderLineInput($this->gaseosa, 2, [])],
        );

        $this->assertCount(2, $editado->items);
        $this->assertSame(30_000_00, $editado->totalCents);
        $this->assertSame(30_000_00, $editado->subtotalCents);
    }

    /**
     * Principio 8: lo que ya estaba conserva su precio, lo nuevo entra con el
     * de hoy. Si se recalculara todo, subir la carta reescribiria pedidos que
     * el cliente ya vio.
     */
    public function testLoViejoConservaSuPrecioYLoNuevoEntraConElDeHoy(): void
    {
        $pedido = $this->pedido();
        MenuService::updateItem($this->tenantId, $this->hamburguesa, ['base_price' => '30000.00']);

        $editado = OrderService::addLines(
            $this->tenantId,
            $pedido->id,
            [new OrderLineInput($this->hamburguesa, 1, [])],
        );

        $precios = array_map(static fn ($i) => $i->unitPriceCents, $editado->items);
        sort($precios);
        $this->assertSame([20_000_00, 30_000_00], $precios);
        $this->assertSame(50_000_00, $editado->totalCents);
    }

    public function testQuitarUnaLineaBajaElTotal(): void
    {
        $pedido = $this->pedido();
        $conGaseosa = OrderService::addLines(
            $this->tenantId,
            $pedido->id,
            [new OrderLineInput($this->gaseosa, 1, [])],
        );

        $gaseosa = null;
        foreach ($conGaseosa->items as $item) {
            if ($item->nameSnapshot === 'Gaseosa') {
                $gaseosa = $item;
            }
        }

        $editado = OrderService::removeLine($this->tenantId, $pedido->id, $gaseosa->id);

        $this->assertCount(1, $editado->items);
        $this->assertSame(20_000_00, $editado->totalCents);
    }

    public function testNoSePuedeDejarElPedidoVacio(): void
    {
        $pedido = $this->pedido();

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/sin productos/');
        OrderService::removeLine($this->tenantId, $pedido->id, $pedido->items[0]->id);
    }

    public function testCambiarLaCantidadRecalculaLaLineaYElPedido(): void
    {
        $pedido = $this->pedido();

        $editado = OrderService::setLineQuantity($this->tenantId, $pedido->id, $pedido->items[0]->id, 3);

        $this->assertSame(3, $editado->items[0]->quantity);
        $this->assertSame(60_000_00, $editado->items[0]->lineTotalCents);
        $this->assertSame(60_000_00, $editado->totalCents);
        // El precio unitario congelado no se toca.
        $this->assertSame(20_000_00, $editado->items[0]->unitPriceCents);
    }

    /** Una linea de otro pedido no existe desde este, aunque el id sea real. */
    public function testNoSeTocaUnaLineaDeOtroPedido(): void
    {
        $mio = $this->pedido();
        $ajeno = $this->pedido();

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/no pertenece a este pedido/');
        OrderService::removeLine($this->tenantId, $mio->id, $ajeno->items[0]->id);
    }

    /**
     * La regla del dinero contra la base: con el pedido cobrado, quitar
     * dejaria plata sin venta que la respalde.
     */
    public function testNoSeQuitaLoQueYaEstaCobrado(): void
    {
        $pedido = $this->pedido();
        $conGaseosa = OrderService::addLines(
            $this->tenantId,
            $pedido->id,
            [new OrderLineInput($this->gaseosa, 1, [])],
        );
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $conGaseosa->totalCents);

        $gaseosa = null;
        foreach ($conGaseosa->items as $item) {
            if ($item->nameSnapshot === 'Gaseosa') {
                $gaseosa = $item;
            }
        }

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/Reembolsa la diferencia/');
        OrderService::removeLine($this->tenantId, $pedido->id, $gaseosa->id);
    }

    /** Agregar a un pedido ya cobrado si se puede: deja saldo pendiente. */
    public function testAgregarAUnPedidoCobradoDejaSaldoPendiente(): void
    {
        $pedido = $this->pedido();
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);

        $editado = OrderService::addLines(
            $this->tenantId,
            $pedido->id,
            [new OrderLineInput($this->gaseosa, 1, [])],
        );

        $saldo = PaymentService::getBalanceForOrder($editado);
        $this->assertSame(5_000_00, $saldo->pendingCents);
        $this->assertFalse($saldo->isSettled);
    }

    /** Fuera de la cocina ya no se edita: el pedido salio. */
    public function testUnPedidoEntregadoYaNoSeEdita(): void
    {
        $pedido = $this->pedido();
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);

        // Por el camino que configuro el restaurante, no saltando al final:
        // la maquina de estados solo deja las transiciones que existen.
        $todos = \App\Core\Permissions::codes();
        $estados = new OrderStatusRepository(Database::app());
        foreach (['paid', 'preparing', 'ready', 'delivered'] as $code) {
            OrderStatusService::advanceStatus(
                $this->tenantId,
                $pedido->id,
                $estados->getByCode($this->tenantId, $code)->id,
                $todos,
                null,
            );
        }

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/ya no se puede modificar/');
        OrderService::addLines($this->tenantId, $pedido->id, [new OrderLineInput($this->gaseosa, 1, [])]);
    }

    /** Cada cambio queda en la misma bitacora que ya lee el detalle. */
    public function testCadaCambioQuedaEnLaBitacora(): void
    {
        $pedido = $this->pedido();
        OrderService::addLines($this->tenantId, $pedido->id, [new OrderLineInput($this->gaseosa, 2, [])]);
        OrderService::setLineQuantity($this->tenantId, $pedido->id, $pedido->items[0]->id, 2);

        $notas = array_map(
            static fn ($e) => $e->note,
            (new OrderRepository(Database::app()))->statusHistory($pedido->id),
        );

        $this->assertContains('Agrego 2x Gaseosa', $notas);
        $this->assertContains('Cambio Hamburguesa de 1 a 2', $notas);
    }

    /**
     * Un combo congela lo que lleva ya multiplicado, asi que cambiar la
     * cantidad tiene que volver a multiplicarlo: si no, tres combos seguirian
     * pidiendole a la cocina una sola hamburguesa.
     */
    public function testCambiarLaCantidadDeUnComboReescalaLoQueLleva(): void
    {
        $combo = MenuService::createItem(
            $this->tenantId,
            MenuService::createCategory($this->tenantId, 'Combos')->id,
            'Combo',
            22_000_00,
        )->id;
        MenuService::setComponents($this->tenantId, $combo, [
            ['item_id' => $this->hamburguesa, 'quantity' => 1],
            ['item_id' => $this->gaseosa, 'quantity' => 2],
        ]);

        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($combo, 1, [])],
        );

        $editado = OrderService::setLineQuantity($this->tenantId, $pedido->id, $pedido->items[0]->id, 3);

        $cantidades = array_map(static fn ($c) => $c->quantity, $editado->items[0]->components);
        $this->assertSame([3, 6], $cantidades);
    }

    /** El impuesto de la linea se recalcula con la tarifa congelada, no con la de hoy. */
    public function testElImpuestoSeRecalculaConLaTarifaCongelada(): void
    {
        $iva = (new \App\Repositories\TaxRateRepository(Database::app()))->getDefault($this->tenantId);
        $conIva = MenuService::createItem(
            $this->tenantId,
            MenuService::createCategory($this->tenantId, 'Con impuesto')->id,
            'Plato con IVA',
            10_000_00,
            taxRateId: $iva->id,
        )->id;

        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($conIva, 1, [])],
        );
        $unaLinea = $pedido->items[0];

        $editado = OrderService::setLineQuantity($this->tenantId, $pedido->id, $unaLinea->id, 2);

        // El doble de unidades es exactamente el doble de impuesto y de total.
        $this->assertSame($unaLinea->taxAmountCents * 2, $editado->items[0]->taxAmountCents);
        $this->assertSame($unaLinea->lineTotalCents * 2, $editado->items[0]->lineTotalCents);
        $this->assertSame($editado->items[0]->lineTotalCents, $editado->subtotalCents);
    }
}
