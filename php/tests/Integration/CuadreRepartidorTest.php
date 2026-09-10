<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\DeliveryInput;
use App\Services\DeliveryService;
use App\Services\DeliveryServiceError;
use App\Services\MenuService;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\PaymentService;

/**
 * Cuadre del repartidor (F9.1).
 *
 * El repartidor vuelve con el efectivo de varios pedidos y hasta ahora
 * nadie cuadraba eso contra nada: la diferencia aparecia —si aparecia— en el
 * arqueo del cajon, donde ya no se puede decir de que pedido salio.
 *
 * Lo que se prueba es lo que hace confiable la cifra: que salga de los
 * cobros reales, que un reembolso la baje sola y que cuadrar cierre la
 * ventana, para que el mismo cobro no se le exija dos veces.
 */
final class CuadreRepartidorTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $itemId;
    private string $courierId;

    protected function setUp(): void
    {
        [$tenant, $branch, $admin] = $this->nuevaEmpresa('delivery');
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Pizza', 30_000_00)->id;

        $pdo = Database::app();
        $roles = new RoleRepository($pdo);
        $rol = $roles->create($this->tenantId, 'repartidor', 'Repartidor');
        $roles->setPermissions($rol->id, ['orders.view', 'delivery.complete']);
        $this->courierId = (new UserRepository($pdo, $roles))->create(
            $this->tenantId,
            $rol->id,
            $this->branchId,
            'Luis Moto',
            'luis-' . bin2hex(random_bytes(3)) . '@prueba.test',
            'x',
        )->id;
    }

    /** Un domicilio asignado a Luis, cobrado en efectivo. */
    private function domicilio(int $cents, string $metodo = 'cash'): object
    {
        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'delivery',
            [new OrderLineInput($this->itemId, 1)],
            delivery: new DeliveryInput('Calle 1 #2-3'),
        );
        DeliveryService::assignCourier($this->tenantId, $pedido->id, $this->courierId);
        PaymentService::registerPayment($this->tenantId, $pedido->id, $metodo, $cents);
        return $pedido;
    }

    private function pendiente(): ?array
    {
        foreach (DeliveryService::pendingSettlements($this->tenantId, $this->branchId) as $fila) {
            if ($fila['courier_id'] === $this->courierId) {
                return $fila;
            }
        }
        return null;
    }

    public function testLoQueDebeTraerSaleDeSusCobrosEnEfectivo(): void
    {
        $this->domicilio(30_000_00);
        $this->domicilio(30_000_00);

        $pendiente = $this->pendiente();
        $this->assertSame(2, $pendiente['orders']);
        $this->assertSame('60000.00', $pendiente['expected_cash']);
    }

    /** Lo que el cliente pago con tarjeta no pasa por sus manos. */
    public function testLoCobradoConTarjetaNoSeLeExige(): void
    {
        $this->domicilio(30_000_00);
        $this->domicilio(30_000_00, 'card');

        $this->assertSame('30000.00', $this->pendiente()['expected_cash']);
    }

    /**
     * Un reembolso baja lo que debe traer, y sin que nadie lo recuerde: la
     * cifra se calcula cada vez.
     */
    public function testUnReembolsoLeBajaLoQueDebeTraer(): void
    {
        $pedido = $this->domicilio(30_000_00);
        $pago = PaymentService::listPayments($this->tenantId, $pedido->id)[0];
        PaymentService::refundPayment($this->tenantId, $pedido->id, $pago->id, null, null);

        $this->assertSame('0.00', $this->pendiente()['expected_cash']);
    }

    public function testCuadrarDevuelveLaDiferenciaCalculada(): void
    {
        $this->domicilio(30_000_00);

        $cuadre = DeliveryService::settleCourier(
            $this->tenantId,
            $this->branchId,
            $this->courierId,
            28_000_00,
            'Le faltaron 2000',
            null,
        );

        $this->assertSame('30000.00', $cuadre['expected_cash']);
        $this->assertSame('28000.00', $cuadre['counted_cash']);
        $this->assertSame('-2000.00', $cuadre['difference']);
        $this->assertSame('Faltan 2000.00', $cuadre['summary']);
    }

    /**
     * La ventana: lo ya cuadrado no se le vuelve a exigir, y lo que cobre
     * despues si.
     */
    public function testLoYaCuadradoNoSeVuelveAExigir(): void
    {
        $this->domicilio(30_000_00);
        DeliveryService::settleCourier($this->tenantId, $this->branchId, $this->courierId, 30_000_00, null, null);

        $this->assertNull($this->pendiente(), 'Despues de cuadrar no deberia quedar nada pendiente');

        // Como otra peticion: dentro de una misma transaccion todo comparte
        // el `now()` de su inicio, asi que el cobro siguiente caeria en el
        // mismo instante que el cuadre y la ventana no se veria.
        $this->comoEmpresa($this->tenantId);
        $this->domicilio(30_000_00);
        $this->assertSame('30000.00', $this->pendiente()['expected_cash']);
    }

    public function testNoSeCuadraAQuienNoDebeNada(): void
    {
        $this->expectException(DeliveryServiceError::class);
        $this->expectExceptionMessageMatches('/no tiene efectivo pendiente/');
        DeliveryService::settleCourier($this->tenantId, $this->branchId, $this->courierId, 0, null, null);
    }

    public function testElCuadreQuedaEnElHistorial(): void
    {
        $this->domicilio(30_000_00);
        DeliveryService::settleCourier($this->tenantId, $this->branchId, $this->courierId, 30_000_00, 'Todo bien', null);

        $historial = DeliveryService::settlementHistory($this->tenantId, $this->branchId);
        $this->assertCount(1, $historial);
        $this->assertSame('Luis Moto', $historial[0]['courier_name']);
        $this->assertSame('30000.00', $historial[0]['counted_cash']);
        $this->assertSame('Todo bien', $historial[0]['note']);
    }

    /** El detalle dice de que pedidos sale la cifra. */
    public function testElDetalleListaSusPedidos(): void
    {
        $pedido = $this->domicilio(30_000_00);

        $detalle = DeliveryService::courierDetail($this->tenantId, $this->branchId, $this->courierId);

        $this->assertSame([$pedido->orderNumber], array_column($detalle['orders'], 'order_number'));
        $this->assertSame('30000.00', $detalle['orders'][0]['cash']);
    }
}
