<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Repositories\MenuRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Services\MenuService;
use App\Services\ModifierService;
use App\Services\OrderError;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\OrderStatusError;
use App\Services\OrderStatusService;
use App\Services\PaymentError;
use App\Services\PaymentService;

/**
 * El ciclo completo de un pedido contra Postgres: crearlo, cobrarlo,
 * reembolsarlo y avanzarlo.
 *
 * Es donde se ven las reglas que ninguna prueba de dominio puede comprobar
 * sola: la llave de idempotencia (que necesita el indice unico de la base),
 * el bloqueo del grupo obligatorio (que necesita el join al menu) y las tres
 * reglas de `StatusChangeRules` contra el saldo real.
 */
final class PedidoYDineroTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $itemId;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa();
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Platos');
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Plato', 20_000_00)->id;
    }

    /** @param string[] $modifierIds */
    private function crear(array $modifierIds = [], ?string $llave = null, int $cantidad = 1): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->itemId, $cantidad, $modifierIds)],
            idempotencyKey: $llave,
        );
    }

    public function testUnPedidoNaceEnElEstadoInicialYConSuTotal(): void
    {
        $pedido = $this->crear();

        $this->assertSame(20_000_00, $pedido->totalCents);
        $inicial = (new OrderStatusRepository(Database::app()))->getInitial($this->tenantId);
        $this->assertSame($inicial->id, $pedido->statusId);
        $this->assertNotSame('', $pedido->orderNumber);
    }

    /**
     * La llave de idempotencia: el mismo intento devuelve el mismo pedido en
     * vez de crear otro. Es lo que hace segura la cola sin red de F3.5.
     */
    public function testLaMismaLlaveNoCreaDosPedidos(): void
    {
        $llave = 'llave-' . bin2hex(random_bytes(6));

        $primero = $this->crear(llave: $llave);
        $segundo = $this->crear(llave: $llave);

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame($primero->orderNumber, $segundo->orderNumber);
    }

    public function testLlavesDistintasCreanPedidosDistintos(): void
    {
        $primero = $this->crear(llave: 'a-' . bin2hex(random_bytes(6)));
        $segundo = $this->crear(llave: 'b-' . bin2hex(random_bytes(6)));

        $this->assertNotSame($primero->id, $segundo->id);
    }

    /** Un grupo obligatorio sin elegir no deja crear el pedido. */
    public function testElGrupoObligatorioSeExigeAlCrear(): void
    {
        $grupo = ModifierService::createGroup($this->tenantId, 'Punto', 1, 1, true);
        ModifierService::createModifier($this->tenantId, $grupo->id, 'Al punto', 0, true);
        ModifierService::setItemGroups($this->tenantId, $this->itemId, [$grupo->id]);

        $this->expectException(OrderError::class);
        $this->expectExceptionMessageMatches('/Punto/');
        $this->crear();
    }

    public function testEligiendoDelGrupoElPedidoEntraYSumaElDelta(): void
    {
        $grupo = ModifierService::createGroup($this->tenantId, 'Adicion', 0, 1, false);
        $opcion = ModifierService::createModifier($this->tenantId, $grupo->id, 'Tocineta', 3_000_00, true);
        ModifierService::setItemGroups($this->tenantId, $this->itemId, [$grupo->id]);

        $pedido = $this->crear([$opcion->id]);
        $this->assertSame(23_000_00, $pedido->totalCents);
    }

    // ---------- dinero ----------

    public function testCobrarPorPartesHastaSaldar(): void
    {
        $pedido = $this->crear();

        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', 5_000_00);
        $saldo = PaymentService::getBalanceForOrder($this->recargar($pedido->id));
        $this->assertSame(15_000_00, $saldo->pendingCents);
        $this->assertFalse($saldo->isSettled);

        PaymentService::registerPayment($this->tenantId, $pedido->id, 'card', 15_000_00);
        $saldo = PaymentService::getBalanceForOrder($this->recargar($pedido->id));
        $this->assertSame(0, $saldo->pendingCents);
        $this->assertTrue($saldo->isSettled);
    }

    public function testNoSeCobraDeMas(): void
    {
        $pedido = $this->crear();

        $this->expectException(PaymentError::class);
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', 25_000_00);
    }

    /**
     * El reembolso no toca el cobro: es una fila nueva que lo apunta. El
     * cobro sigue contando aunque quede marcado, y quien lo revierte es su
     * reembolso — descontarlo dos veces dejaria el saldo al doble.
     */
    public function testElReembolsoEsUnaFilaNuevaYElSaldoNoSeCuentaDosVeces(): void
    {
        $pedido = $this->crear();
        $cobro = PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', 20_000_00);

        PaymentService::refundPayment($this->tenantId, $pedido->id, $cobro->id, 5_000_00, 'Se cobro de mas');

        $saldo = PaymentService::getBalanceForOrder($this->recargar($pedido->id));
        $this->assertSame(20_000_00, $saldo->paidCents);
        $this->assertSame(5_000_00, $saldo->refundedCents);
        $this->assertSame(15_000_00, $saldo->netPaidCents);
        $this->assertSame(5_000_00, $saldo->pendingCents);
    }

    public function testUnReembolsoNoSeReembolsa(): void
    {
        $pedido = $this->crear();
        $cobro = PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', 20_000_00);
        $devolucion = PaymentService::refundPayment($this->tenantId, $pedido->id, $cobro->id, 5_000_00, null);

        $this->expectException(PaymentError::class);
        PaymentService::refundPayment($this->tenantId, $pedido->id, $devolucion->id, 1_000_00, null);
    }

    // ---------- estados ----------

    /** @return object el pedido recargado */
    private function recargar(string $orderId): object
    {
        return (new OrderRepository(Database::app()))->getById($this->tenantId, $orderId);
    }

    private function estado(string $code): string
    {
        return (new OrderStatusRepository(Database::app()))->getByCode($this->tenantId, $code)->id;
    }

    /** @param string[] $permisos */
    private function avanzar(string $orderId, string $code, array $permisos, ?string $nota = null): void
    {
        OrderStatusService::advanceStatus($this->tenantId, $orderId, $this->estado($code), $permisos, null, $nota);
    }

    public function testNoSeCompletaUnPedidoSinSaldar(): void
    {
        $pedido = $this->crear();
        $todos = \App\Core\Permissions::codes();

        $this->avanzar($pedido->id, 'paid', $todos);
        $this->avanzar($pedido->id, 'preparing', $todos);
        $this->avanzar($pedido->id, 'ready', $todos);

        $this->expectException(OrderStatusError::class);
        $this->expectExceptionMessageMatches('/salda/i');
        $this->avanzar($pedido->id, 'delivered', $todos);
    }

    public function testSaldadoSiSeCompleta(): void
    {
        $pedido = $this->crear();
        $todos = \App\Core\Permissions::codes();
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', 20_000_00);

        foreach (['paid', 'preparing', 'ready', 'delivered'] as $code) {
            $this->avanzar($pedido->id, $code, $todos);
        }

        $this->assertSame($this->estado('delivered'), $this->recargar($pedido->id)->statusId);
    }

    /** Anular exige motivo, y no se puede con plata encima. */
    public function testAnularExigeMotivo(): void
    {
        $pedido = $this->crear();

        $this->expectException(OrderStatusError::class);
        $this->expectExceptionMessageMatches('/motivo|por que|por qué/i');
        $this->avanzar($pedido->id, 'cancelled', \App\Core\Permissions::codes());
    }

    public function testUnPedidoConPlataEncimaNoSeAnula(): void
    {
        $pedido = $this->crear();
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', 20_000_00);

        $this->expectException(OrderStatusError::class);
        $this->expectExceptionMessageMatches('/reembolsa/i');
        $this->avanzar($pedido->id, 'cancelled', \App\Core\Permissions::codes(), 'El cliente se arrepintio');
    }

    /** Reembolsado entero sí se anula: ese es el camino que la regla obliga. */
    public function testReembolsadoEnteroSeAnula(): void
    {
        $pedido = $this->crear();
        $cobro = PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', 20_000_00);
        PaymentService::refundPayment($this->tenantId, $pedido->id, $cobro->id, 20_000_00, 'Se anula');

        $this->avanzar($pedido->id, 'cancelled', \App\Core\Permissions::codes(), 'El cliente se arrepintio');

        $this->assertSame($this->estado('cancelled'), $this->recargar($pedido->id)->statusId);
    }

    public function testSinElPermisoDeLaTransicionNoSeAvanza(): void
    {
        $pedido = $this->crear();

        $this->expectException(OrderStatusError::class);
        $this->expectExceptionMessageMatches('/permiso/i');
        $this->avanzar($pedido->id, 'paid', ['orders.view']);
    }
}
