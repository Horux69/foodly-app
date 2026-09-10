<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Repositories\DiscountReasonRepository;
use App\Repositories\OrderRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\MenuService;
use App\Services\OrderError;
use App\Services\OrderLineInput;
use App\Services\OrderService;

/**
 * Descuentos con motivo, autor y tope (F7.2).
 *
 * El descuento era un numero suelto en el cuerpo de POST /orders: cualquiera
 * con caja podia cerrar un pedido de 80.000 en 0 y el reporte lo listaba sin
 * poder decir por que. Lo que se prueba aqui es que ahora no se pueda.
 */
final class DescuentosTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $itemId;
    private string $adminId;

    protected function setUp(): void
    {
        [$tenant, $branch, $admin] = $this->nuevaEmpresa();
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;
        $this->adminId = $admin->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Plato', 100_000_00)->id;
    }

    private function motivo(string $nombre = 'Cortesía'): string
    {
        foreach ((new DiscountReasonRepository(Database::app()))->listForTenant($this->tenantId) as $m) {
            if ($m['name'] === $nombre) {
                return $m['id'];
            }
        }
        self::fail("La empresa nueva no trae el motivo '{$nombre}'");
    }

    private function pedido(int $descuentoCents = 0, ?string $reasonId = null, ?string $userId = null): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            $userId,
            'counter',
            [new OrderLineInput($this->itemId, 1, [])],
            discountCents: $descuentoCents,
            discountReasonId: $reasonId,
        );
    }

    /** Una empresa nueva ya trae con qué justificar el primer descuento. */
    public function testUnaEmpresaNuevaTraeSusMotivos(): void
    {
        $motivos = (new DiscountReasonRepository(Database::app()))->listForTenant($this->tenantId);

        $this->assertSame(
            ['Cortesía', 'Reclamo del cliente', 'Empleado', 'Convenio'],
            array_column($motivos, 'name'),
        );
    }

    public function testUnDescuentoConMotivoSeAplicaYBajaElTotal(): void
    {
        $pedido = $this->pedido(20_000_00, $this->motivo());

        $this->assertSame(80_000_00, $pedido->totalCents);
        $this->assertSame(20_000_00, $pedido->discountCents);
    }

    public function testSinMotivoElPedidoSeRechaza(): void
    {
        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/motivo/');
        $this->pedido(20_000_00, null);
    }

    /** Un motivo de otra empresa no existe desde aqui. */
    public function testUnMotivoAjenoNoSirve(): void
    {
        [$otro] = $this->nuevaEmpresa();
        $ajeno = (new DiscountReasonRepository(Database::app()))->listForTenant($otro->id)[0]['id'];
        $this->comoEmpresa($this->tenantId);

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/no existe o esta desactivado/');
        $this->pedido(10_000_00, $ajeno);
    }

    public function testUnMotivoDesactivadoTampoco(): void
    {
        $reasons = new DiscountReasonRepository(Database::app());
        $motivo = $this->motivo();
        $reasons->setActive($this->tenantId, $motivo, false);

        $this->expectException(OrderError::class);
        $this->pedido(10_000_00, $motivo);
    }

    /**
     * El tope del rol: un cajero resuelve un reclamo pequeno sin llamar a
     * nadie, pero el 100% lo autoriza otra persona.
     */
    public function testElTopeDelRolFrenaUnDescuentoGrande(): void
    {
        $pdo = Database::app();
        $roles = new RoleRepository($pdo);
        $cajero = $roles->create($this->tenantId, 'cajero', 'Cajero');
        $roles->setPermissions($cajero->id, ['orders.create', 'orders.discount', 'orders.view']);
        $pdo->prepare('UPDATE roles SET max_discount_percent = 10 WHERE id = :id')->execute(['id' => $cajero->id]);

        $usuario = (new UserRepository($pdo, $roles))->create(
            $this->tenantId,
            $cajero->id,
            $this->branchId,
            'Cajero de prueba',
            'cajero-' . bin2hex(random_bytes(3)) . '@prueba.test',
            'x',
        );

        // 10% de 100.000 cabe.
        $this->pedido(10_000_00, $this->motivo(), $usuario->id);

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/necesita autorizacion/');
        $this->pedido(30_000_00, $this->motivo(), $usuario->id);
    }

    /** El admin no tiene tope: su rol lo deja en null. */
    public function testElAdminNoTieneTope(): void
    {
        $pedido = $this->pedido(100_000_00, $this->motivo(), $this->adminId);
        $this->assertSame(0, $pedido->totalCents);
    }

    public function testSeAplicaSobreUnPedidoYaAbierto(): void
    {
        $pedido = $this->pedido();

        $conDescuento = OrderService::applyDiscount(
            $this->tenantId,
            $pedido->id,
            25_000_00,
            $this->motivo(),
            $this->adminId,
        );

        $this->assertSame(75_000_00, $conDescuento->totalCents);
        $this->assertSame(25_000_00, $conDescuento->discountCents);
    }

    /** Quitarlo no necesita motivo: dejar de regalar plata no se justifica. */
    public function testQuitarloDevuelveElTotal(): void
    {
        $pedido = $this->pedido(30_000_00, $this->motivo(), $this->adminId);

        $sinDescuento = OrderService::applyDiscount($this->tenantId, $pedido->id, 0, null, $this->adminId);

        $this->assertSame(100_000_00, $sinDescuento->totalCents);
        $this->assertSame(0, $sinDescuento->discountCents);
    }

    public function testQuedaEnLaBitacoraConSuMotivo(): void
    {
        $pedido = $this->pedido();
        OrderService::applyDiscount($this->tenantId, $pedido->id, 15_000_00, $this->motivo(), $this->adminId);

        $notas = array_map(
            static fn ($e) => $e->note,
            (new OrderRepository(Database::app()))->statusHistory($pedido->id),
        );

        $this->assertContains('Descuento de 15000.00 (15%) por Cortesía', $notas);
    }

    /** Sobre un pedido ya entregado no se descuenta: la venta esta cerrada. */
    public function testNoSeDescuentaUnPedidoQueYaSalio(): void
    {
        $pedido = $this->pedido();
        \App\Services\PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);

        $estados = new \App\Repositories\OrderStatusRepository(Database::app());
        foreach (['paid', 'preparing', 'ready', 'delivered'] as $code) {
            \App\Services\OrderStatusService::advanceStatus(
                $this->tenantId,
                $pedido->id,
                $estados->getByCode($this->tenantId, $code)->id,
                \App\Core\Permissions::codes(),
                null,
            );
        }

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/ya no se puede modificar/');
        OrderService::applyDiscount($this->tenantId, $pedido->id, 1_000_00, $this->motivo(), $this->adminId);
    }
}
