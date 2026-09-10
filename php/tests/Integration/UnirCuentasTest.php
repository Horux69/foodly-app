<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Core\Permissions;
use App\Repositories\OrderRepository;
use App\Repositories\TableRepository;
use App\Services\FloorService;
use App\Services\MenuService;
use App\Services\OrderError;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\PaymentService;

/**
 * Mover de mesa y unir cuentas (F4.3).
 *
 * Las dos operaciones mueven plata de sitio sin cobrarla, que es donde mas
 * facil es que desaparezca. Lo que se prueba es que quede rastro y que la
 * cuenta que se vacia no se quede abierta sin lineas.
 */
final class UnirCuentasTest extends IntegrationTestCase
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
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Plato', 20_000_00)->id;

        $tables = new TableRepository(Database::app());
        foreach (['M1', 'M2'] as $code) {
            $tables->create($this->branchId, $code, 4);
        }
    }

    private function cuentaEn(?string $mesa): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'table',
            [new OrderLineInput($this->itemId, 1, [])],
            tableCode: $mesa,
        );
    }

    private function notas(string $orderId): array
    {
        return array_map(
            static fn ($e) => $e->note,
            (new OrderRepository(Database::app()))->statusHistory($orderId),
        );
    }

    public function testUnPedidoSeMueveDeMesa(): void
    {
        $pedido = $this->cuentaEn('M1');

        $movido = OrderService::moveToTable($this->tenantId, $pedido->id, 'M2');

        $this->assertSame('M2', $movido->tableCode);
        $this->assertContains('Movido de la mesa M1 a la M2', $this->notas($pedido->id));
    }

    public function testNoSeMueveAUnaMesaQueNoExiste(): void
    {
        $pedido = $this->cuentaEn('M1');

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/no existe en esta sucursal/');
        OrderService::moveToTable($this->tenantId, $pedido->id, 'M99');
    }

    public function testUnirPasaLasLineasYSumaLosTotales(): void
    {
        $destino = $this->cuentaEn('M1');
        $origen = $this->cuentaEn('M2');

        $unido = OrderService::mergeOrders($this->tenantId, $destino->id, $origen->id, Permissions::codes());

        $this->assertCount(2, $unido->items);
        $this->assertSame(40_000_00, $unido->totalCents);
    }

    /** La que se vacia se anula: un pedido sin lineas no puede quedar abierto. */
    public function testLaCuentaQueSeUneQuedaAnuladaYEnCero(): void
    {
        $destino = $this->cuentaEn('M1');
        $origen = $this->cuentaEn('M2');

        OrderService::mergeOrders($this->tenantId, $destino->id, $origen->id, Permissions::codes());

        $vacia = (new OrderRepository(Database::app()))->getById($this->tenantId, $origen->id);
        $this->assertSame('cancelled', $vacia->status->category);
        $this->assertSame(0, $vacia->totalCents);
        $this->assertCount(0, $vacia->items);
    }

    /** Las dos bitácoras dicen a dónde fue y qué llegó. */
    public function testLasDosBitacorasLoCuentan(): void
    {
        $destino = $this->cuentaEn('M1');
        $origen = $this->cuentaEn('M2');

        OrderService::mergeOrders($this->tenantId, $destino->id, $origen->id, Permissions::codes());

        $this->assertContains("Unida a {$destino->orderNumber}", $this->notas($origen->id));
        $this->assertContains("Se le unio {$origen->orderNumber}", $this->notas($destino->id));
    }

    /**
     * Con un cobro por medio habria que decidir a que venta pertenece, y esa
     * decision no la puede tomar el sistema.
     */
    public function testNoSeUneUnaCuentaConPlataEncima(): void
    {
        $destino = $this->cuentaEn('M1');
        $origen = $this->cuentaEn('M2');
        PaymentService::registerPayment($this->tenantId, $origen->id, 'cash', 5_000_00);

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/reembolsalos antes de unir/');
        OrderService::mergeOrders($this->tenantId, $destino->id, $origen->id, Permissions::codes());
    }

    public function testUnPedidoNoSeUneConsigoMismo(): void
    {
        $pedido = $this->cuentaEn('M1');

        $this->expectException(OrderError::class);
        OrderService::mergeOrders($this->tenantId, $pedido->id, $pedido->id, Permissions::codes());
    }

    /** Tras unir, la mesa que se vació queda libre en el salón. */
    public function testLaMesaQueSeVacioQuedaLibre(): void
    {
        $destino = $this->cuentaEn('M1');
        $origen = $this->cuentaEn('M2');

        OrderService::mergeOrders($this->tenantId, $destino->id, $origen->id, Permissions::codes());

        $porCodigo = [];
        foreach (FloorService::tableStatus($this->tenantId, $this->branchId) as $mesa) {
            $porCodigo[$mesa['code']] = $mesa;
        }

        $this->assertNull($porCodigo['M2']['order_id']);
        $this->assertSame($destino->id, $porCodigo['M1']['order_id']);
    }
}
