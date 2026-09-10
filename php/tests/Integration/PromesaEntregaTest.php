<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Core\Permissions;
use App\Domain\DeliveryPromise;
use App\Repositories\DeliveryRepository;
use App\Repositories\OrderStatusRepository;
use App\Services\DeliveryInput;
use App\Services\DeliveryService;
use App\Services\MenuService;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\PaymentService;
use App\Services\ReportService;

/**
 * Promesa de entrega y retrasos (F9.5).
 *
 * `delivery_zones.est_minutes` existia y no lo miraba nadie: era un numero
 * de configuracion sin consecuencia. Lo que se prueba es que se convierta en
 * una hora concreta al tomar el pedido —es lo que se le dijo al cliente— y
 * que el reporte cuente contra los que prometieron algo, no contra todos.
 */
final class PromesaEntregaTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $itemId;
    private string $zoneId;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa('delivery');
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $this->itemId = MenuService::createItem($this->tenantId, $categoria->id, 'Pizza', 30_000_00)->id;

        $this->zoneId = DeliveryService::createZone(
            $this->tenantId,
            $this->branchId,
            'Centro',
            5_000_00,
            0,
            40,
        )->id;
    }

    private function pedido(?string $zoneId): object
    {
        return OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'delivery',
            [new OrderLineInput($this->itemId, 1)],
            delivery: new DeliveryInput('Calle 45 #12-34', $zoneId),
        );
    }

    private function entrega(string $orderId): object
    {
        return (new DeliveryRepository(Database::app()))->getForOrder($orderId);
    }

    /** La promesa sale del tiempo estimado de la zona, al tomar el pedido. */
    public function testLaZonaPoneLaHoraPrometida(): void
    {
        $pedido = $this->pedido($this->zoneId);
        $entrega = $this->entrega($pedido->id);

        $this->assertNotNull($entrega->estimatedTime);
        $minutos = (new \DateTimeImmutable($entrega->estimatedTime))->getTimestamp()
            - (new \DateTimeImmutable($pedido->createdAt))->getTimestamp();
        // 40 minutos, con holgura para lo que tarde la propia transaccion.
        $this->assertGreaterThan(39 * 60, $minutos);
        $this->assertLessThan(41 * 60, $minutos);
    }

    /** Sin zona no hay promesa: prometer un tiempo inventado es peor. */
    public function testSinZonaNoSePrometeNada(): void
    {
        $entrega = $this->entrega($this->pedido(null)->id);

        $this->assertNull($entrega->estimatedTime);
        $this->assertSame(
            DeliveryPromise::SIN_PROMESA,
            DeliveryPromise::estado($entrega->estimatedTime, $entrega->deliveredAt),
        );
    }

    /** Una zona sin tiempo estimado tampoco promete. */
    public function testUnaZonaSinTiempoEstimadoNoPromete(): void
    {
        $zona = DeliveryService::createZone($this->tenantId, $this->branchId, 'Lejos', 8_000_00, 0, null);

        $this->assertNull($this->entrega($this->pedido($zona->id)->id)->estimatedTime);
    }

    /**
     * El reporte mide contra los que prometieron algo. Un pedido sin zona no
     * prometio nada, y contarlo como incumplido seria mentir al reves.
     */
    public function testElReporteCuentaSoloLoQueSePrometio(): void
    {
        $conZona = $this->pedido($this->zoneId);
        $sinZona = $this->pedido(null);

        foreach ([$conZona, $sinZona] as $pedido) {
            PaymentService::registerPayment($this->tenantId, $pedido->id, 'cash', $pedido->totalCents);
            $this->avanzar($pedido->id, ['paid', 'preparing', 'ready', 'in_transit', 'delivered']);
        }

        $hoy = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $reporte = ReportService::deliveryPromise($this->tenantId, $this->branchId, $hoy, $hoy);

        $this->assertSame(2, (int) $reporte['delivered']);
        $this->assertSame(1, (int) $reporte['promised']);
        // Entregado en el mismo instante: dentro de los 40 minutos.
        $this->assertSame(1, (int) $reporte['on_time']);
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
}
