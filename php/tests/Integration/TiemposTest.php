<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Core\Permissions;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Repositories\TableRepository;
use App\Services\KitchenService;
use App\Services\MenuService;
use App\Services\OrderError;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\PaymentService;

/**
 * Tiempos de la cuenta y marchar (F4.5).
 *
 * En una mesa se pide todo junto y no se cocina todo junto. Lo que se prueba
 * es que solo salga a la cocina lo marchado —si saliera todo, los postres se
 * prepararian con las entradas y volveriamos al problema— y que el primer
 * tiempo salga solo, porque un mesero que no conozca la funcion no puede
 * dejar la comida sin pedir.
 */
final class TiemposTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $plato;
    private string $postre;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa('table_service');
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->plato = MenuService::createItem($this->tenantId, $categoria->id, 'Plato', 20_000_00)->id;
        $this->postre = MenuService::createItem($this->tenantId, $categoria->id, 'Postre', 8_000_00)->id;

        (new TableRepository(Database::app()))->create($this->branchId, 'M1', 4);
    }

    private function cuenta(array $lineas): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'table',
            $lineas,
            tableCode: 'M1',
        );
    }

    /** @return array<string, ?string> nombre de la linea => cuando salio */
    private function salidas(string $orderId): array
    {
        $orden = (new OrderRepository(Database::app()))->getById($this->tenantId, $orderId);
        $salidas = [];
        foreach ($orden->items as $item) {
            $salidas[$item->nameSnapshot] = $item->firedAt;
        }
        return $salidas;
    }

    /** El restaurante de mesa nace con tres tiempos configurados. */
    public function testUnRestauranteDeMesaTraeSusTiempos(): void
    {
        $this->assertSame(['Entradas', 'Fuertes', 'Postres'], OrderService::tiemposDe($this->tenantId));
    }

    /**
     * El primero sale solo y los demas esperan. Si el primero tambien
     * esperara, un mesero que no conociera la funcion dejaria la comida sin
     * pedir y el error se descubriria cuando el cliente preguntara.
     */
    public function testSoloElPrimerTiempoSaleAlTomarElPedido(): void
    {
        $pedido = $this->cuenta([
            new OrderLineInput($this->plato, 1, course: 1),
            new OrderLineInput($this->postre, 1, course: 3),
        ]);

        $salidas = $this->salidas($pedido->id);
        $this->assertNotNull($salidas['Plato']);
        $this->assertNull($salidas['Postre']);
    }

    /** El primero con lineas, no el numero 1. */
    public function testSiNoHayEntradasSaleElPrimeroQueSiTengaLineas(): void
    {
        $pedido = $this->cuenta([
            new OrderLineInput($this->plato, 1, course: 2),
            new OrderLineInput($this->postre, 1, course: 3),
        ]);

        $salidas = $this->salidas($pedido->id);
        $this->assertNotNull($salidas['Plato']);
        $this->assertNull($salidas['Postre']);
    }

    public function testMarcharMandaElTiempoALaCocinaYLoDejaEnLaBitacora(): void
    {
        $pedido = $this->cuenta([
            new OrderLineInput($this->plato, 1, course: 1),
            new OrderLineInput($this->postre, 1, course: 3),
        ]);

        [, $cuantas] = OrderService::fireCourse($this->tenantId, $pedido->id, 3);

        $this->assertSame(1, $cuantas);
        $this->assertNotNull($this->salidas($pedido->id)['Postre']);

        $notas = array_map(
            static fn ($e) => $e->note,
            (new OrderRepository(Database::app()))->statusHistory($pedido->id),
        );
        $this->assertContains('Marchado: Postres', $notas);
    }

    /**
     * Volver a marchar no reescribe la hora: es lo que la cocina lee para
     * saber que es nuevo.
     */
    public function testUnTiempoYaMarchadoNoSeVuelveAMarchar(): void
    {
        $pedido = $this->cuenta([new OrderLineInput($this->plato, 1, course: 1)]);

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/no tiene nada por marchar/');
        OrderService::fireCourse($this->tenantId, $pedido->id, 1);
    }

    public function testUnTiempoQueElRestauranteNoTieneSeRechazaAlTomarElPedido(): void
    {
        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/no existe/');
        $this->cuenta([new OrderLineInput($this->plato, 1, course: 7)]);
    }

    /** El tablero solo ve lo marchado, y dice lo que falta. */
    public function testElTableroSoloVeLoMarchadoYAvisaLoQueFalta(): void
    {
        $pedido = $this->cuenta([
            new OrderLineInput($this->plato, 1, course: 1),
            new OrderLineInput($this->postre, 1, course: 3),
        ]);

        $tablero = KitchenService::getBoard($this->tenantId, $this->branchId);
        $enTablero = array_values(array_filter(
            $tablero->orders,
            fn ($o) => $o->order->id === $pedido->id,
        ))[0];

        $marchadas = array_filter($enTablero->order->items, static fn ($i) => $i->firedAt !== null);
        $this->assertCount(1, $marchadas);
        $this->assertSame(['Entradas', 'Fuertes', 'Postres'], $tablero->courses);
    }

    /**
     * Una linea que llega a un tiempo ya marchado sale de una vez: si
     * esperara, se quedaria esperando a que alguien marche un tiempo que ya
     * se marcho, o sea para siempre.
     */
    public function testLoQueSeAgregaAUnTiempoYaMarchadoSaleDeUnaVez(): void
    {
        $pedido = $this->cuenta([new OrderLineInput($this->plato, 1, course: 1)]);

        OrderService::addLines($this->tenantId, $pedido->id, [
            new OrderLineInput($this->postre, 1, course: 1),
            new OrderLineInput($this->postre, 2, course: 3),
        ]);

        $orden = (new OrderRepository(Database::app()))->getById($this->tenantId, $pedido->id);
        $porTiempo = [];
        foreach ($orden->items as $item) {
            $porTiempo[$item->course][] = $item->firedAt;
        }

        $this->assertNotNull($porTiempo[1][1], 'Lo agregado al tiempo ya marchado tiene que salir ya');
        $this->assertNull($porTiempo[3][0], 'Lo de un tiempo que no salio espera');
    }

    /** Con la cuenta cerrada no se marcha nada mas. */
    public function testCerradaLaCuentaNoSeMarchaNada(): void
    {
        $pedido = $this->cuenta([
            new OrderLineInput($this->plato, 1, course: 1),
            new OrderLineInput($this->postre, 1, course: 3),
        ]);
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
        OrderService::fireCourse($this->tenantId, $pedido->id, 3);
    }

    /** Un mostrador no tiene tiempos y no cambia nada: todo sale al crear. */
    public function testUnMostradorNoTieneTiemposYTodoSaleAlCrear(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa('fast_food');
        $categoria = MenuService::createCategory($tenant->id, 'Carta');
        $item = MenuService::createItem($tenant->id, $categoria->id, 'Hamburguesa', 15_000_00)->id;

        $this->assertSame([], OrderService::tiemposDe($tenant->id));

        $pedido = OrderService::createOrder(
            $tenant->id,
            $branch->id,
            null,
            'counter',
            [new OrderLineInput($item, 1)],
        );

        $this->assertNotNull($pedido->items[0]->firedAt);
    }
}
