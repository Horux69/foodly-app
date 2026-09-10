<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Repositories\FiscalRepository;
use App\Services\FiscalService;
use App\Services\FiscalServiceError;
use App\Services\MenuService;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\PaymentService;

/**
 * El documento fiscal de una venta (F12.1).
 *
 * Lo que se prueba aqui es lo que decide si el restaurante puede usar esto
 * como caja: que el consecutivo no se repita ni se salga del rango, que
 * emitir dos veces devuelva el mismo documento, y que un proveedor caido no
 * impida vender.
 */
final class DocumentoFiscalTest extends IntegrationTestCase
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

    private function resolucion(int $from = 1, int $to = 100, ?string $validUntil = null): array
    {
        return FiscalService::createResolution(
            $this->tenantId,
            $this->branchId,
            '18764000001234',
            'POS',
            $from,
            $to,
            $validUntil,
        );
    }

    private function ventaSaldada(): object
    {
        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->itemId, 1, [])],
        );
        PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);
        return $pedido;
    }

    public function testEmiteConElPrimerConsecutivoDelRango(): void
    {
        $this->resolucion();
        $pedido = $this->ventaSaldada();

        [$doc, $yaExistia] = FiscalService::emit($this->tenantId, $pedido->id);

        $this->assertFalse($yaExistia);
        $this->assertSame('POS1', $doc['full_number']);
        $this->assertSame(20_000_00, $doc['total']);
    }

    public function testCadaVentaSeLlevaElSiguiente(): void
    {
        $this->resolucion();

        $numeros = [];
        for ($i = 0; $i < 3; $i++) {
            [$doc] = FiscalService::emit($this->tenantId, $this->ventaSaldada()->id);
            $numeros[] = $doc['full_number'];
        }

        $this->assertSame(['POS1', 'POS2', 'POS3'], $numeros);
    }

    /**
     * Emitir dos veces la misma venta no se corrige sin una nota de credito:
     * volver a pedirlo devuelve el que ya existe.
     */
    public function testEmitirDosVecesDevuelveElMismoDocumento(): void
    {
        $this->resolucion();
        $pedido = $this->ventaSaldada();

        [$primero] = FiscalService::emit($this->tenantId, $pedido->id);
        [$segundo, $yaExistia] = FiscalService::emit($this->tenantId, $pedido->id);

        $this->assertTrue($yaExistia);
        $this->assertSame($primero['id'], $segundo['id']);
        $this->assertSame($primero['full_number'], $segundo['full_number']);
    }

    /** El documento dice cuanto se cobro: emitirlo antes es prometer una cifra que puede cambiar. */
    public function testNoSeEmiteSobreUnPedidoSinSaldar(): void
    {
        $this->resolucion();
        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->itemId, 1, [])],
        );

        $this->expectException(FiscalServiceError::class);
        $this->expectExceptionMessageMatches('/no esta saldado/');
        FiscalService::emit($this->tenantId, $pedido->id);
    }

    public function testSinResolucionNoSeEmite(): void
    {
        $pedido = $this->ventaSaldada();

        $this->expectException(FiscalServiceError::class);
        $this->expectExceptionMessageMatches('/resolucion de numeracion activa/');
        FiscalService::emit($this->tenantId, $pedido->id);
    }

    public function testSeAcabaElRangoYLoDice(): void
    {
        $this->resolucion(from: 1, to: 1);
        FiscalService::emit($this->tenantId, $this->ventaSaldada()->id);

        $this->expectException(FiscalServiceError::class);
        $this->expectExceptionMessageMatches('/Se acabo el rango/');
        FiscalService::emit($this->tenantId, $this->ventaSaldada()->id);
    }

    public function testUnaResolucionVencidaNoNumera(): void
    {
        $this->resolucion(validUntil: '2020-01-01');
        $pedido = $this->ventaSaldada();

        $this->expectException(FiscalServiceError::class);
        $this->expectExceptionMessageMatches('/vencio/');
        FiscalService::emit($this->tenantId, $pedido->id);
    }

    /**
     * Sin proveedor conectado el documento queda numerado y en contingencia,
     * no aceptado: decir 'accepted' sin haber transmitido nada seria mentir
     * donde la mentira la descubre la autoridad.
     */
    public function testSinProveedorQuedaEnContingencia(): void
    {
        $this->resolucion();

        [$doc] = FiscalService::emit($this->tenantId, $this->ventaSaldada()->id);

        $this->assertSame('contingency', $doc['status']);
        $this->assertSame('local', $doc['provider']);
        $this->assertNull($doc['transmitted_at']);
    }

    /** Una resolucion nueva apaga la anterior: dos activas repartirian consecutivos. */
    public function testCrearUnaResolucionApagaLaAnterior(): void
    {
        $this->resolucion(from: 1, to: 10);
        $this->resolucion(from: 500, to: 600);

        $activas = array_filter(FiscalService::resolutions($this->tenantId), static fn ($r) => $r['is_active']);
        $this->assertCount(1, $activas);

        [$doc] = FiscalService::emit($this->tenantId, $this->ventaSaldada()->id);
        $this->assertSame('POS500', $doc['full_number']);
    }

    public function testElRangoAlRevesSeRechaza(): void
    {
        $this->expectException(FiscalServiceError::class);
        $this->resolucion(from: 100, to: 10);
    }

    /** Los que quedaron sin transmitir se pueden listar para reintentarlos. */
    public function testLosPendientesSePuedenReintentar(): void
    {
        $this->resolucion();
        FiscalService::emit($this->tenantId, $this->ventaSaldada()->id);

        $pendientes = (new FiscalRepository(Database::app()))->pendingTransmission($this->tenantId);
        $this->assertCount(1, $pendientes);

        // El proveedor local nunca acepta, asi que el reintento no cambia
        // nada — pero tampoco pierde el documento ni lo renumera.
        $resultado = FiscalService::retryPending($this->tenantId);
        $this->assertSame(0, $resultado['enviados']);
        $this->assertSame(1, $resultado['pendientes']);
    }

    /** Un documento de otra empresa no se ve desde aqui. */
    public function testNoSeVeElDocumentoDeOtraEmpresa(): void
    {
        $this->resolucion();
        $pedido = $this->ventaSaldada();
        FiscalService::emit($this->tenantId, $pedido->id);

        [$otro] = $this->nuevaEmpresa();
        $this->comoEmpresa($otro->id);

        $this->expectException(FiscalServiceError::class);
        FiscalService::forOrder($otro->id, $pedido->id);
    }
}
