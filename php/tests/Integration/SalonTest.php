<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Repositories\TableRepository;
use App\Services\FloorService;
use App\Services\MenuService;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\PaymentService;

/**
 * El estado de las mesas (F4.1).
 *
 * Hasta ahora `tables` solo servia para escribir el codigo en el pedido: no
 * habia forma de ver cuales estaban ocupadas. Lo que se prueba aqui es que
 * la ocupacion salga de la categoria del estado y no de una columna
 * guardada, que es lo que se desincroniza en cuanto alguien cobra desde otra
 * pantalla.
 */
final class SalonTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $itemId;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa('table_service');
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Plato', 30_000_00)->id;
    }

    private function mesa(string $code, int $capacity = 4): void
    {
        (new TableRepository(Database::app()))->create($this->branchId, $code, $capacity);
    }

    private function sentarEn(string $code): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'table',
            [new OrderLineInput($this->itemId, 1, [])],
            tableCode: $code,
        );
    }

    /** @return array<string, array<string, mixed>> por código de mesa */
    private function salon(): array
    {
        $porCodigo = [];
        foreach (FloorService::tableStatus($this->tenantId, $this->branchId) as $mesa) {
            $porCodigo[$mesa['code']] = $mesa;
        }
        return $porCodigo;
    }

    public function testUnaMesaSinPedidoEstaLibre(): void
    {
        $this->mesa('M1');

        $mesa = $this->salon()['M1'];
        $this->assertNull($mesa['order_id']);
        $this->assertNull($mesa['occupied_minutes']);
        $this->assertSame(0, $mesa['open_orders']);
    }

    public function testUnaMesaConPedidoTraeSuCuentaYSuTotal(): void
    {
        $this->mesa('M1');
        $pedido = $this->sentarEn('M1');

        $mesa = $this->salon()['M1'];
        $this->assertSame($pedido->id, $mesa['order_id']);
        $this->assertSame($pedido->orderNumber, $mesa['order_number']);
        $this->assertSame('30000.00', $mesa['total']);
        $this->assertSame(1, $mesa['open_orders']);
        $this->assertNotNull($mesa['occupied_minutes']);
    }

    /**
     * La ocupacion sale de la categoria del estado: al completarse el
     * pedido, la mesa se libera sola sin que nadie toque una columna.
     */
    public function testAlCerrarElPedidoLaMesaSeLibera(): void
    {
        $this->mesa('M1');
        $pedido = $this->sentarEn('M1');
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);

        // Por el camino que configura un restaurante de mesa: abierto →
        // preparación → listo → servido, que es el de categoría completed.
        $estados = new \App\Repositories\OrderStatusRepository(Database::app());
        foreach (['preparing', 'ready', 'served'] as $code) {
            OrderStatusService::advanceStatus(
                $this->tenantId,
                $pedido->id,
                $estados->getByCode($this->tenantId, $code)->id,
                \App\Core\Permissions::codes(),
                null,
            );
        }

        $this->assertNull($this->salon()['M1']['order_id'], 'La mesa deberia quedar libre al cerrarse la cuenta');
    }

    /** Dos cuentas en la misma mesa se dicen, no se esconden. */
    public function testDosCuentasEnLaMismaMesaSeCuentan(): void
    {
        $this->mesa('M1');
        $this->sentarEn('M1');
        $this->sentarEn('M1');

        $this->assertSame(2, $this->salon()['M1']['open_orders']);
    }

    /** Se muestra la cuenta mas antigua: es la que lleva mas rato en la mesa. */
    public function testSeMuestraLaCuentaMasAntigua(): void
    {
        $this->mesa('M1');
        $primera = $this->sentarEn('M1');
        $this->sentarEn('M1');

        $this->assertSame($primera->id, $this->salon()['M1']['order_id']);
    }

    public function testLasMesasDeOtraSucursalNoAparecen(): void
    {
        $this->mesa('M1');
        $otra = (new \App\Repositories\BranchRepository(Database::app()))
            ->create($this->tenantId, 'Sede 2', 'SD2', 'America/Bogota', null, null);
        (new TableRepository(Database::app()))->create($otra->id, 'X9', 4);

        $codigos = array_keys($this->salon());
        $this->assertSame(['M1'], $codigos);
    }

    public function testUnaSucursalAjenaNoSeConsulta(): void
    {
        [$otro, $sucursalAjena] = $this->nuevaEmpresa();
        $this->comoEmpresa($this->tenantId);

        $this->expectException(\App\Services\FloorError::class);
        FloorService::tableStatus($this->tenantId, $sucursalAjena->id);
    }
}
