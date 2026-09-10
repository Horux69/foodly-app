<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Core\Permissions;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Repositories\ReportRepository;
use App\Repositories\RoleRepository;
use App\Repositories\TableRepository;
use App\Repositories\UserRepository;
use App\Services\FloorService;
use App\Services\MenuService;
use App\Services\OrderError;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\PaymentService;

/**
 * El mesero a cargo de la cuenta (F4.4).
 *
 * Hasta ahora un pedido solo sabia quien lo digito, y de ahi salia el
 * reporte "por usuario". En un restaurante de servicio esas dos personas no
 * son la misma, y de la diferencia depende el reparto de la propina —que es
 * plata de alguien—, asi que lo que se prueba es que el nombre quede pegado
 * a la cuenta y que no se pueda reescribir cuando ya se repartio.
 */
final class MeseroTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $adminId;
    private string $itemId;

    protected function setUp(): void
    {
        [$tenant, $branch, $admin] = $this->nuevaEmpresa('table_service');
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;
        $this->adminId = $admin->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Plato', 20_000_00)->id;

        (new TableRepository(Database::app()))->create($this->branchId, 'M1', 4);
    }

    /** Un usuario nuevo con el rol que se le indique. */
    private function usuario(string $nombre, array $permisos): string
    {
        $pdo = Database::app();
        $roles = new RoleRepository($pdo);
        $sufijo = bin2hex(random_bytes(3));
        $rol = $roles->create($this->tenantId, "rol-{$sufijo}", $nombre);
        $roles->setPermissions($rol->id, $permisos);

        return (new UserRepository($pdo, $roles))->create(
            $this->tenantId,
            $rol->id,
            $this->branchId,
            $nombre,
            "{$sufijo}@prueba.test",
            'x',
        )->id;
    }

    private function cuenta(?string $creadaPor = null, ?string $mesero = null, int $cantidad = 1): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            $creadaPor,
            'table',
            [new OrderLineInput($this->itemId, $cantidad, [])],
            tableCode: 'M1',
            serverId: $mesero,
        );
    }

    private function notas(string $orderId): array
    {
        return array_map(
            static fn ($e) => $e->note,
            (new OrderRepository(Database::app()))->statusHistory($orderId),
        );
    }

    /**
     * Sin decir nada, atiende quien tomo el pedido. Es lo cierto en la
     * mayoria de los casos, y sin el defecto el reporte por mesero
     * arrancaria con una montonera de ventas sin dueño.
     */
    public function testPorDefectoAtiendeQuienTomoElPedido(): void
    {
        $pedido = $this->cuenta($this->adminId);

        $this->assertSame($this->adminId, $pedido->serverId);
        $this->assertSame('Admin de prueba', $pedido->serverName);
    }

    /** La tableta compartida: la toma el cajero y la atiende Ana. */
    public function testSeTomaANombreDeOtro(): void
    {
        $ana = $this->usuario('Ana', ['orders.create', 'orders.view']);

        $pedido = $this->cuenta($this->adminId, $ana);

        $this->assertSame($ana, $pedido->serverId);
        $this->assertSame('Ana', $pedido->serverName);
    }

    /**
     * Quien no puede tomar pedidos no atiende mesas. Sin esto se le podria
     * asignar una cuenta —y con ella su parte de la propina— al contador.
     */
    public function testUnRolQueNoTomaPedidosNoAtiendeMesas(): void
    {
        $contador = $this->usuario('Contador', ['reports.view']);

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/no puede atender una cuenta/');
        $this->cuenta($this->adminId, $contador);
    }

    public function testCambiarElMeseroDejaElNombreAnteriorEnLaBitacora(): void
    {
        $ana = $this->usuario('Ana', ['orders.create']);
        $luis = $this->usuario('Luis', ['orders.create']);
        $pedido = $this->cuenta($this->adminId, $ana);

        $cambiado = OrderService::assignServer($this->tenantId, $pedido->id, $luis, $this->adminId);

        $this->assertSame('Luis', $cambiado->serverName);
        $this->assertContains('Mesero cambiado de Ana a Luis', $this->notas($pedido->id));
    }

    /** Dejarla sin mesero es una eleccion valida, no un campo olvidado. */
    public function testSePuedeDejarSinMesero(): void
    {
        $ana = $this->usuario('Ana', ['orders.create']);
        $pedido = $this->cuenta($this->adminId, $ana);

        $sin = OrderService::assignServer($this->tenantId, $pedido->id, null, $this->adminId);

        $this->assertNull($sin->serverId);
        $this->assertContains('Se quito a Ana de la cuenta', $this->notas($pedido->id));
    }

    /**
     * Con la cuenta cerrada ya no: la propina de ese turno se repartio con
     * un nombre y cambiarlo reescribiria un reporte que quizas ya se pago.
     */
    public function testCerradaLaCuentaElMeseroNoSeCambia(): void
    {
        $ana = $this->usuario('Ana', ['orders.create']);
        $pedido = $this->cuenta($this->adminId, $ana);
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);

        $estados = new OrderStatusRepository(Database::app());
        foreach (['preparing', 'ready', 'served'] as $code) {
            OrderStatusService::advanceStatus(
                $this->tenantId,
                $pedido->id,
                $estados->getByCode($this->tenantId, $code)->id,
                Permissions::codes(),
                null,
            );
        }

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/ya esta cerrada/');
        OrderService::assignServer($this->tenantId, $pedido->id, $this->adminId, $this->adminId);
    }

    /**
     * Con el pedido en la cocina si: un cambio de turno a mitad de servicio
     * es de todos los dias, y ahi la ventana de editar productos ya se
     * cerraria para el pedido listo.
     */
    public function testConElPedidoListoTodaviaSeCambia(): void
    {
        $ana = $this->usuario('Ana', ['orders.create']);
        $pedido = $this->cuenta($this->adminId);

        $estados = new OrderStatusRepository(Database::app());
        foreach (['preparing', 'ready'] as $code) {
            OrderStatusService::advanceStatus(
                $this->tenantId,
                $pedido->id,
                $estados->getByCode($this->tenantId, $code)->id,
                Permissions::codes(),
                null,
            );
        }

        $cambiado = OrderService::assignServer($this->tenantId, $pedido->id, $ana, $this->adminId);
        $this->assertSame('Ana', $cambiado->serverName);
    }

    /** El salon dice quien atiende cada mesa: es el "mis mesas" de la tableta. */
    public function testElSalonDiceQuienAtiende(): void
    {
        $ana = $this->usuario('Ana', ['orders.create']);
        $this->cuenta($this->adminId, $ana);

        $mesas = FloorService::tableStatus($this->tenantId, $this->branchId);
        $m1 = array_values(array_filter($mesas, static fn ($m) => $m['code'] === 'M1'))[0];

        $this->assertSame($ana, $m1['server_id']);
        $this->assertSame('Ana', $m1['server_name']);
    }

    /**
     * El reporte separa la venta de la propina: sumadas, quien recibio mas
     * propina apareceria vendiendo mas, que es lo contrario de lo que el
     * reporte quiere mostrar.
     */
    public function testElReportePorMeseroSeparaLaVentaDeLaPropina(): void
    {
        $ana = $this->usuario('Ana', ['orders.create']);
        $pedido = $this->cuenta($this->adminId, $ana);
        OrderService::applyTip($this->tenantId, $pedido->id, 2_000_00, $this->adminId);

        $saldo = PaymentService::getBalanceForOrder(OrderService::getOrder($this->tenantId, $pedido->id));
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $saldo->pendingCents);

        $estados = new OrderStatusRepository(Database::app());
        foreach (['preparing', 'ready', 'served'] as $code) {
            OrderStatusService::advanceStatus(
                $this->tenantId,
                $pedido->id,
                $estados->getByCode($this->tenantId, $code)->id,
                Permissions::codes(),
                null,
            );
        }

        $hoy = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $filas = (new ReportRepository(Database::app()))
            ->salesByServer($this->tenantId, $this->branchId, $hoy, $hoy);

        $deAna = array_values(array_filter($filas, static fn ($f) => $f['user_id'] === $ana));
        $this->assertCount(1, $deAna, 'La venta deberia contarse a nombre de Ana');
        $this->assertSame('20000.00', (string) $deAna[0]['revenue']);
        $this->assertSame('2000.00', (string) $deAna[0]['tips']);
    }
}
