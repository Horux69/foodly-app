<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Services\CashSessionError;
use App\Services\CashSessionService;
use App\Services\MenuService;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\PaymentError;
use App\Services\PaymentService;
use App\Services\RegisterError;
use App\Services\RegisterService;

/**
 * Varias cajas por sucursal (F7.4).
 *
 * Con una sola caja —el caso de casi todos— nada cambia: el turno cuelga de
 * la sucursal como siempre. Lo que se prueba es lo nuevo: dos cajones que
 * cuadran por separado, sin que el faltante de uno se tape con el sobrante
 * del otro.
 */
final class VariasCajasTest extends IntegrationTestCase
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
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Combo', 20_000_00)->id;
    }

    private function pedido(): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->itemId, 1)],
        );
    }

    /** Sin cajas configuradas, un turno abierto sigue siendo el de la sucursal. */
    public function testSinCajasConfiguradasElTurnoEsDeLaSucursal(): void
    {
        $vista = CashSessionService::open($this->tenantId, $this->branchId, null, 0);

        $this->assertNull($vista->session->registerId);
        $this->assertSame($vista->session->id, CashSessionService::current($this->tenantId, $this->branchId)->id);
    }

    public function testDosCajasAbrenTurnosIndependientes(): void
    {
        $mostrador = RegisterService::create($this->tenantId, $this->branchId, 'Mostrador');
        $barra = RegisterService::create($this->tenantId, $this->branchId, 'Barra');

        $v1 = CashSessionService::open($this->tenantId, $this->branchId, null, 50_000_00, $mostrador->id);
        $v2 = CashSessionService::open($this->tenantId, $this->branchId, null, 30_000_00, $barra->id);

        $this->assertSame($mostrador->id, $v1->session->registerId);
        $this->assertSame($barra->id, $v2->session->registerId);
        $this->assertSame($mostrador->id, CashSessionService::current($this->tenantId, $this->branchId, $mostrador->id)->registerId);
        $this->assertSame($barra->id, CashSessionService::current($this->tenantId, $this->branchId, $barra->id)->registerId);
    }

    /** La misma caja no puede tener dos turnos abiertos a la vez. */
    public function testLaMismaCajaNoAbreDosVeces(): void
    {
        $mostrador = RegisterService::create($this->tenantId, $this->branchId, 'Mostrador');
        CashSessionService::open($this->tenantId, $this->branchId, null, 0, $mostrador->id);

        $this->expectException(CashSessionError::class);
        $this->expectExceptionMessageMatches('/ya tiene un turno abierto/');
        CashSessionService::open($this->tenantId, $this->branchId, null, 0, $mostrador->id);
    }

    /** Cada cobro se registra en su propia caja: no se cuadra en la del vecino. */
    public function testCadaCobroEntraASuPropiaCaja(): void
    {
        $mostrador = RegisterService::create($this->tenantId, $this->branchId, 'Mostrador');
        $barra = RegisterService::create($this->tenantId, $this->branchId, 'Barra');
        $v1 = CashSessionService::open($this->tenantId, $this->branchId, null, 0, $mostrador->id);
        $v2 = CashSessionService::open($this->tenantId, $this->branchId, null, 0, $barra->id);

        $pedido = $this->pedido();
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents, registerId: $mostrador->id);

        $cuadreMostrador = CashSessionService::view($v1->session);
        $cuadreBarra = CashSessionService::view($v2->session);

        $this->assertSame(2_000_000, $cuadreMostrador->totals->chargedCents);
        $this->assertSame(0, $cuadreBarra->totals->chargedCents);
    }

    /**
     * Con dos cajas abiertas y sin decir en cual se esta cobrando, se
     * rechaza: mandar la plata a la equivocada solo se descubre en el
     * arqueo, cuando a una le sobra lo que a la otra le falta.
     */
    public function testCobrarSinElegirCajaConDosAbiertasSeRechaza(): void
    {
        $mostrador = RegisterService::create($this->tenantId, $this->branchId, 'Mostrador');
        $barra = RegisterService::create($this->tenantId, $this->branchId, 'Barra');
        CashSessionService::open($this->tenantId, $this->branchId, null, 0, $mostrador->id);
        CashSessionService::open($this->tenantId, $this->branchId, null, 0, $barra->id);

        $pedido = $this->pedido();

        $this->expectException(PaymentError::class);
        $this->expectExceptionMessageMatches('/mas de una caja abierta/');
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);
    }

    /** Abrir en una caja de otra sucursal no vale. */
    public function testNoSeAbreEnLaCajaDeOtraSucursal(): void
    {
        [$otroTenant, $otraSede] = $this->nuevaEmpresa('fast_food');
        $ajena = RegisterService::create($otroTenant->id, $otraSede->id, 'Del otro lado');
        $this->comoEmpresa($this->tenantId);

        $this->expectException(CashSessionError::class);
        $this->expectExceptionMessageMatches('/no existe en esta sucursal/');
        CashSessionService::open($this->tenantId, $this->branchId, null, 0, $ajena->id);
    }

    /** Una caja con turnos encima no se borra, se apaga. */
    public function testUnaCajaConTurnosNoSeBorra(): void
    {
        $mostrador = RegisterService::create($this->tenantId, $this->branchId, 'Mostrador');
        CashSessionService::open($this->tenantId, $this->branchId, null, 0, $mostrador->id);

        $this->expectException(RegisterError::class);
        $this->expectExceptionMessageMatches('/1 turno/');
        RegisterService::delete($this->tenantId, $mostrador->id);
    }

    public function testNoSeRepiteElNombreDeLaCajaEnLaMismaSucursal(): void
    {
        RegisterService::create($this->tenantId, $this->branchId, 'Mostrador');

        $this->expectException(RegisterError::class);
        $this->expectExceptionMessageMatches('/Ya existe/');
        RegisterService::create($this->tenantId, $this->branchId, 'Mostrador');
    }

    /** Una caja apagada no admite abrir turno. */
    public function testUnaCajaApagadaNoAbre(): void
    {
        $mostrador = RegisterService::create($this->tenantId, $this->branchId, 'Mostrador');
        RegisterService::update($this->tenantId, $mostrador->id, 'Mostrador', false);

        $this->expectException(CashSessionError::class);
        $this->expectExceptionMessageMatches('/apagada/');
        CashSessionService::open($this->tenantId, $this->branchId, null, 0, $mostrador->id);
    }
}
