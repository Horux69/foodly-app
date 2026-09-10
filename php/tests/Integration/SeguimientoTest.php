<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Core\Permissions;
use App\Repositories\OrderStatusRepository;
use App\Services\DeliveryInput;
use App\Services\DeliveryService;
use App\Services\MenuService;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use App\Services\TrackingError;
use App\Services\TrackingService;

/**
 * Seguimiento publico del pedido (F9.2).
 *
 * Es el unico camino que responde sin sesion, asi que lo que se prueba no es
 * solo que funcione sino lo que **no** devuelve: sin sesion, cualquier campo
 * de mas es una filtracion. Y que el token de una empresa no alcance el
 * pedido de otra, que es lo que RLS tiene que garantizar tambien aqui.
 */
final class SeguimientoTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $itemId;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa('delivery');
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Pizza', 30_000_00)->id;
    }

    private function pedido(bool $domicilio = true): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            // Un tenant de domicilios no vende en mostrador: el canal que
            // si tiene y no lleva entrega es 'whatsapp'.
            $domicilio ? 'delivery' : 'whatsapp',
            [new OrderLineInput($this->itemId, 2)],
            customerPhone: '3001112233',
            customerName: 'Ana Cliente',
            delivery: $domicilio ? new DeliveryInput('Calle 45 #12-34') : null,
        );
    }

    public function testCadaPedidoNaceConSuEnlace(): void
    {
        $pedido = $this->pedido();

        $this->assertNotNull($pedido->trackingToken);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $pedido->trackingToken);
    }

    public function testDosPedidosNoCompartenElToken(): void
    {
        $this->assertNotSame($this->pedido()->trackingToken, $this->pedido()->trackingToken);
    }

    public function testElSeguimientoDiceEnQuePasoVa(): void
    {
        $pedido = $this->pedido();

        $datos = TrackingService::track($pedido->trackingToken);

        $this->assertSame($pedido->orderNumber, $datos['order_number']);
        $this->assertSame(['Recibido', 'En preparación', 'Listo', 'En camino', 'Entregado'], $datos['steps']);
        $this->assertSame(0, $datos['step']);
        $this->assertFalse($datos['cancelled']);
        $this->assertSame('Calle 45 #12-34', $datos['address']);
        $this->assertSame([['quantity' => 2, 'name' => 'Pizza']], $datos['items']);
    }

    /** Un pedido para recoger no pasa por "en camino": ese paso nunca se encenderia. */
    public function testUnPedidoQueNoEsDomicilioTieneOtroRecorrido(): void
    {
        $datos = TrackingService::track($this->pedido(domicilio: false)->trackingToken);

        $this->assertSame(['Recibido', 'En preparación', 'Listo', 'Entregado'], $datos['steps']);
        $this->assertFalse($datos['is_delivery']);
        $this->assertNull($datos['address']);
    }

    /**
     * Sin sesion, cada campo de mas es una filtracion: el telefono del
     * cliente, su nombre, quien tomo el pedido, los ids internos.
     */
    public function testNoDevuelveNadaQueElClienteNoSepaYaDeSuPedido(): void
    {
        $datos = TrackingService::track($this->pedido()->trackingToken);

        $plano = json_encode($datos, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('3001112233', $plano, 'El telefono del cliente no sale');
        $this->assertStringNotContainsString('Ana Cliente', $plano, 'El nombre del cliente no sale');
        $this->assertArrayNotHasKey('id', $datos);
        $this->assertArrayNotHasKey('customer_id', $datos);
        $this->assertArrayNotHasKey('balance', $datos);
        $this->assertArrayNotHasKey('tracking_token', $datos);
    }

    public function testElPasoAvanzaConElEstado(): void
    {
        $pedido = $this->pedido();
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);
        $this->avanzar($pedido->id, ['paid', 'preparing']);

        $this->assertSame(1, TrackingService::track($pedido->trackingToken)['step']);
    }

    /** Anulado no es el ultimo paso: es la interrupcion del camino. */
    public function testUnPedidoAnuladoLoDiceYNoAparecCompletado(): void
    {
        $pedido = $this->pedido();
        $estados = new OrderStatusRepository(Database::app());
        OrderStatusService::advanceStatus(
            $this->tenantId,
            $pedido->id,
            $estados->getByCode($this->tenantId, 'cancelled')->id,
            Permissions::codes(),
            null,
            'El cliente se arrepintio',
        );

        $datos = TrackingService::track($pedido->trackingToken);
        $this->assertTrue($datos['cancelled']);
        $this->assertNull($datos['step']);
        // El motivo de la anulacion es del restaurante, no del enlace.
        $this->assertStringNotContainsString('arrepintio', json_encode($datos));
    }

    /** El nombre del repartidor solo cuando ya salio, y solo el de pila. */
    public function testElRepartidorAparecerRecienCuandoSale(): void
    {
        $pedido = $this->pedido();
        $courier = $this->repartidor();
        DeliveryService::assignCourier($this->tenantId, $pedido->id, $courier);

        $this->assertNull(TrackingService::track($pedido->trackingToken)['courier_name']);

        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);
        $this->avanzar($pedido->id, ['paid', 'preparing', 'ready', 'in_transit']);

        $this->assertSame('Luis', TrackingService::track($pedido->trackingToken)['courier_name']);
    }

    /** @param string[] $codigos */
    private function avanzar(string $orderId, array $codigos): void
    {
        $estados = new OrderStatusRepository(Database::app());
        foreach ($codigos as $code) {
            OrderStatusService::advanceStatus(
                $this->tenantId,
                $orderId,
                $estados->getByCode($this->tenantId, $code)->id,
                Permissions::codes(),
                null,
            );
        }
    }

    private function repartidor(): string
    {
        $pdo = Database::app();
        $roles = new \App\Repositories\RoleRepository($pdo);
        $rol = $roles->create($this->tenantId, 'moto', 'Repartidor');
        $roles->setPermissions($rol->id, ['orders.view', 'delivery.complete']);
        return (new \App\Repositories\UserRepository($pdo, $roles))->create(
            $this->tenantId,
            $rol->id,
            $this->branchId,
            'Luis Perez',
            'luis-' . bin2hex(random_bytes(3)) . '@prueba.test',
            'x',
        )->id;
    }

    public function testUnTokenInventadoNoEncuentraNada(): void
    {
        $this->expectException(TrackingError::class);
        TrackingService::track(str_repeat('a', 32));
    }

    /** Ni siquiera llega a la base: la forma se comprueba antes. */
    public function testUnTokenConBasuraNiSeConsulta(): void
    {
        $this->expectException(TrackingError::class);
        TrackingService::track("' OR 1=1 --");
    }

    /**
     * El token de una empresa no alcanza el pedido de otra. Lo garantiza
     * RLS: la funcion SECURITY DEFINER solo devuelve el tenant, y de ahi en
     * adelante la lectura va filtrada.
     */
    public function testElTokenDeUnaEmpresaNoLeeElPedidoDeOtra(): void
    {
        $pedido = $this->pedido();
        $token = $pedido->trackingToken;

        [$otro] = $this->nuevaEmpresa('delivery');
        $this->comoEmpresa($otro->id);

        // Aunque la peticion venga con otra empresa fijada, el seguimiento
        // resuelve la suya por el token y responde lo mismo.
        $datos = TrackingService::track($token);
        $this->assertSame($pedido->orderNumber, $datos['order_number']);
    }
}
