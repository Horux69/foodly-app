<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\MenuService;
use App\Services\OrderError;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\PaymentService;

/**
 * La propina se decide al cobrar (F7.3).
 *
 * Antes solo existia al crear el pedido: habia que adivinarla antes de que
 * el cliente pagara. Lo que se prueba aqui es que ponerla suba el saldo por
 * cobrar —para que el cajero cobre una sola vez— y que quitarla lo baje.
 */
final class PropinaTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $itemId;

    protected function setUp(): void
    {
        // table_service porque es el que recibe propina por defecto.
        [$tenant, $branch] = $this->nuevaEmpresa('table_service');
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Plato', 50_000_00)->id;
    }

    private function pedido(): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'table',
            [new OrderLineInput($this->itemId, 1, [])],
        );
    }

    public function testPonerLaPropinaSubeElSaldoPorCobrar(): void
    {
        $pedido = $this->pedido();

        $conPropina = OrderService::applyTip($this->tenantId, $pedido->id, 5_000_00);

        $this->assertSame(55_000_00, $conPropina->totalCents);
        $this->assertSame(5_000_00, $conPropina->tipCents);
        $this->assertSame(55_000_00, PaymentService::getBalanceForOrder($conPropina)->pendingCents);
    }

    public function testQuitarlaLaDevuelveACero(): void
    {
        $pedido = $this->pedido();
        OrderService::applyTip($this->tenantId, $pedido->id, 5_000_00);

        $sinPropina = OrderService::applyTip($this->tenantId, $pedido->id, 0);

        $this->assertSame(50_000_00, $sinPropina->totalCents);
        $this->assertSame(0, $sinPropina->tipCents);
    }

    /** Un cero de mas se frena antes de cobrarlo, no en el arqueo. */
    public function testUnaPropinaMayorQueLaVentaSeRechaza(): void
    {
        $pedido = $this->pedido();

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/revisa el importe/');
        OrderService::applyTip($this->tenantId, $pedido->id, 500_000_00);
    }

    /** Con el pedido ya cobrado, quitar la propina dejaria plata sin venta. */
    public function testNoSeQuitaLaPropinaYaCobrada(): void
    {
        $pedido = $this->pedido();
        $conPropina = OrderService::applyTip($this->tenantId, $pedido->id, 5_000_00);
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $conPropina->totalCents);

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/Reembolsa la diferencia/');
        OrderService::applyTip($this->tenantId, $pedido->id, 0);
    }

    /** Un restaurante que no recibe propina no la recibe por la puerta de atras. */
    public function testUnRestauranteSinPropinaLaRechaza(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa('fast_food');
        $categoria = MenuService::createCategory($tenant->id, 'Carta');
        $item = MenuService::createItem($tenant->id, $categoria->id, 'Plato', 10_000_00);
        $pedido = OrderService::createOrder(
            $tenant->id,
            $branch->id,
            null,
            'counter',
            [new OrderLineInput($item->id, 1, [])],
        );

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/no recibe propina/');
        OrderService::applyTip($tenant->id, $pedido->id, 1_000_00);
    }

    public function testQuedaEnLaBitacora(): void
    {
        $pedido = $this->pedido();
        OrderService::applyTip($this->tenantId, $pedido->id, 5_000_00);

        $notas = array_map(
            static fn ($e) => $e->note,
            (new \App\Repositories\OrderRepository(\App\Core\Database::app()))->statusHistory($pedido->id),
        );

        $this->assertContains('Propina de 5000.00', $notas);
    }
}
