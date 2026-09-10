<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Services\CashSessionError;
use App\Services\CashSessionService;
use App\Services\MenuService;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\PaymentService;

/**
 * Entradas y salidas de efectivo del cajon (F7.1).
 *
 * Lo que se prueba contra la base es que el arqueo las cuente igual que los
 * cobros: el esperado sigue siendo un calculo con los movimientos reales, y
 * ahora los reales incluyen la plata que sale sin ser una venta.
 */
final class MovimientosDeCajaTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $itemId;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa();
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Plato', 20_000_00)->id;
    }

    private function abrirCaja(int $baseCents = 100_000_00): void
    {
        CashSessionService::open($this->tenantId, $this->branchId, null, $baseCents);
    }

    private function cuadre(): object
    {
        return CashSessionService::view(
            CashSessionService::current($this->tenantId, $this->branchId)
        )->totals;
    }

    private function vender(int $cents): void
    {
        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->itemId, 1, [])],
        );
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $cents);
    }

    public function testUnaSalidaBajaElEfectivoEsperado(): void
    {
        $this->abrirCaja();
        $this->vender(20_000_00);

        CashSessionService::registerDrawerMovement(
            $this->tenantId,
            $this->branchId,
            'out',
            30_000_00,
            'Pago del gas',
            null,
        );

        $totales = $this->cuadre();
        $this->assertSame(90_000_00, $totales->expectedCashCents);
        $this->assertSame(30_000_00, $totales->cashOutCents);
        // Lo cobrado no se toca: una sangria no es una devolucion.
        $this->assertSame(20_000_00, $totales->netCollectedCents());
    }

    public function testUnaEntradaLoSube(): void
    {
        $this->abrirCaja();

        CashSessionService::registerDrawerMovement(
            $this->tenantId,
            $this->branchId,
            'in',
            50_000_00,
            'Prestamo de la otra caja',
            null,
        );

        $this->assertSame(150_000_00, $this->cuadre()->expectedCashCents);
    }

    /** Contra lo que hay ahora en el cajon, no contra lo cobrado en el turno. */
    public function testNoSeSacaMasDeLoQueHay(): void
    {
        $this->abrirCaja(10_000_00);

        $this->expectException(CashSessionError::class);
        $this->expectExceptionMessageMatches('/en el cajon hay/');
        CashSessionService::registerDrawerMovement(
            $this->tenantId,
            $this->branchId,
            'out',
            50_000_00,
            'Sangria',
            null,
        );
    }

    /** Sin turno abierto no hay cajon que cuadrar: el movimiento no entraria en ningun arqueo. */
    public function testSinTurnoAbiertoNoSeRegistraNada(): void
    {
        $this->expectException(CashSessionError::class);
        $this->expectExceptionMessageMatches('/turno de caja abierto/');
        CashSessionService::registerDrawerMovement(
            $this->tenantId,
            $this->branchId,
            'out',
            10_000_00,
            'Algo',
            null,
        );
    }

    public function testLosMovimientosSeListanConSuMotivo(): void
    {
        $this->abrirCaja();
        CashSessionService::registerDrawerMovement($this->tenantId, $this->branchId, 'out', 20_000_00, 'Gas', null);
        CashSessionService::registerDrawerMovement($this->tenantId, $this->branchId, 'in', 5_000_00, 'Vuelto', null);

        $lista = CashSessionService::drawerMovements($this->tenantId, $this->branchId);

        $this->assertCount(2, $lista);
        $this->assertSame(['Gas', 'Vuelto'], array_column($lista, 'reason'));
        $this->assertSame(['out', 'in'], array_column($lista, 'kind'));
    }

    /**
     * Cerrado el turno, su cuadre sigue contando lo que salio: el historico
     * de ayer no puede volverse un faltante.
     */
    public function testElTurnoCerradoConservaSusMovimientos(): void
    {
        $this->abrirCaja();
        CashSessionService::registerDrawerMovement($this->tenantId, $this->branchId, 'out', 40_000_00, 'Gas', null);

        $sesion = CashSessionService::current($this->tenantId, $this->branchId);
        CashSessionService::close($this->tenantId, $sesion->id, null, 60_000_00, null);

        $historia = CashSessionService::history($this->tenantId, $this->branchId);
        $this->assertSame(40_000_00, $historia[0]->totals->cashOutCents);
        $this->assertSame(60_000_00, $historia[0]->totals->expectedCashCents);
        $this->assertSame(0, $historia[0]->totals->differenceCents);
    }

    /** Los movimientos de una empresa no se ven desde otra. */
    public function testNoSeVenLosMovimientosDeOtraEmpresa(): void
    {
        $this->abrirCaja();
        CashSessionService::registerDrawerMovement($this->tenantId, $this->branchId, 'out', 10_000_00, 'Gas', null);

        [$otro, $sucursalAjena] = $this->nuevaEmpresa();
        CashSessionService::open($otro->id, $sucursalAjena->id, null, 0);

        $this->assertSame([], CashSessionService::drawerMovements($otro->id, $sucursalAjena->id));
    }
}
