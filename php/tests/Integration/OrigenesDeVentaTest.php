<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Core\Permissions;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Services\MenuService;
use App\Services\OrderError;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use App\Services\ReportService;
use App\Services\SalesSourceError;
use App\Services\SalesSourceService;

/**
 * Origenes de venta y comision (F9.4).
 *
 * Un pedido de Rappi se prepara igual y se cobra igual, pero no vale lo
 * mismo. Lo que se prueba es que la comision se congele con la venta —para
 * que renegociar el contrato no reescriba lo que se gano en marzo— y que el
 * reporte diga el neto, que es la pregunta que viene a responder.
 */
final class OrigenesDeVentaTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $itemId;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa('fast_food');
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Combo', 50_000_00)->id;
    }

    private function pedido(?string $sourceId): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->itemId, 1)],
            salesSourceId: $sourceId,
        );
    }

    public function testUnPedidoSinOrigenEsVentaPropia(): void
    {
        $pedido = $this->pedido(null);
        $fila = $this->fila($pedido->id);

        $this->assertNull($fila['sales_source_id']);
        $this->assertNull($fila['commission_percent']);
    }

    /** @return array<string, mixed> */
    private function fila(string $orderId): array
    {
        $stmt = Database::app()->prepare('SELECT sales_source_id, commission_percent FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        return $stmt->fetch();
    }

    public function testElPorcentajeSeCongelaConLaVenta(): void
    {
        $rappi = SalesSourceService::create($this->tenantId, 'Rappi', 30.0);
        $pedido = $this->pedido($rappi->id);

        $this->assertSame('30.00', (string) $this->fila($pedido->id)['commission_percent']);

        // Se renegocia el contrato: lo vendido no cambia.
        SalesSourceService::update($this->tenantId, $rappi->id, 'Rappi', 22.0, true);

        $this->assertSame('30.00', (string) $this->fila($pedido->id)['commission_percent']);
    }

    public function testUnOrigenApagadoNoRecibePedidos(): void
    {
        $origen = SalesSourceService::create($this->tenantId, 'DiDi', 25.0);
        SalesSourceService::update($this->tenantId, $origen->id, 'DiDi', 25.0, false);

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/apagado/');
        $this->pedido($origen->id);
    }

    public function testUnOrigenInventadoSeRechaza(): void
    {
        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/no existe/');
        $this->pedido('11111111-1111-4111-8111-111111111111');
    }

    public function testNoSeRepiteElNombre(): void
    {
        SalesSourceService::create($this->tenantId, 'Rappi', 30.0);

        $this->expectException(SalesSourceError::class);
        $this->expectExceptionMessageMatches('/Ya existe/');
        SalesSourceService::create($this->tenantId, 'Rappi', 10.0);
    }

    /**
     * Un origen con ventas no se borra: hacerlo convertiria las ventas de
     * Rappi en ventas propias y el reporte mentiria hacia arriba.
     */
    public function testUnOrigenConVentasNoSeBorra(): void
    {
        $rappi = SalesSourceService::create($this->tenantId, 'Rappi', 30.0);
        $this->pedido($rappi->id);

        $this->expectException(SalesSourceError::class);
        $this->expectExceptionMessageMatches('/1 pedido/');
        SalesSourceService::delete($this->tenantId, $rappi->id);
    }

    public function testUnOrigenSinVentasSiSeBorra(): void
    {
        $origen = SalesSourceService::create($this->tenantId, 'Prueba', 0.0);
        SalesSourceService::delete($this->tenantId, $origen->id);

        $this->assertSame([], SalesSourceService::listChannels($this->tenantId));
    }

    /** El reporte dice el bruto, la comision y —lo que importa— el neto. */
    public function testElReporteSeparaLaComisionDelNeto(): void
    {
        $rappi = SalesSourceService::create($this->tenantId, 'Rappi', 30.0);
        $propio = $this->pedido(null);
        $deRappi = $this->pedido($rappi->id);

        foreach ([$propio, $deRappi] as $pedido) {
            PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);
            $this->completar($pedido->id);
        }

        $hoy = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $filas = ReportService::salesBySource($this->tenantId, $this->branchId, $hoy, $hoy);

        $porNombre = [];
        foreach ($filas as $fila) {
            $porNombre[$fila['source_name'] ?? 'propio'] = $fila;
        }

        $this->assertSame('15000.00', (string) $porNombre['Rappi']['commission']);
        $this->assertSame('0.00', (string) $porNombre['propio']['commission']);
    }

    private function completar(string $orderId): void
    {
        $estados = new OrderStatusRepository(Database::app());
        foreach (['paid', 'preparing', 'ready', 'delivered'] as $code) {
            $estado = $estados->getByCode($this->tenantId, $code);
            if ($estado === null) {
                continue;
            }
            OrderStatusService::advanceStatus($this->tenantId, $orderId, $estado->id, Permissions::codes(), null);
        }
    }
}
