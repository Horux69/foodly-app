<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Repositories\MenuRepository;
use App\Repositories\OrderRepository;
use App\Services\MenuService;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\PaymentError;
use App\Services\PaymentService;

/**
 * Que una empresa no vea nada de otra.
 *
 * Es el principio 1 del proyecto y la unica de todas las reglas que no se
 * puede comprobar sin base: RLS vive en Postgres, y lo que la hace funcionar
 * —que el rol de la aplicacion no sea dueno de las tablas— no se ve desde
 * PHP. Una consulta a la que se le olvide el `WHERE tenant_id` sigue estando
 * mal, pero aqui se comprueba que la red de seguridad esta puesta.
 */
final class AislamientoEntreEmpresasTest extends IntegrationTestCase
{
    /** @return array{0: string, 1: string, 2: string} tenant, sucursal y producto */
    private function empresaConMenu(): array
    {
        [$tenant, $branch] = $this->nuevaEmpresa();
        $categoria = MenuService::createCategory($tenant->id, 'Platos');
        $item = MenuService::createItem($tenant->id, $categoria->id, 'Plato', 10_000_00);
        return [$tenant->id, $branch->id, $item->id];
    }

    public function testElMenuDeUnaNoSeVeDesdeLaOtra(): void
    {
        [$primera] = $this->empresaConMenu();
        [$segunda] = $this->empresaConMenu();

        $this->comoEmpresa($primera);
        $suyos = (new MenuRepository(Database::app()))->listAllItems($primera);
        $this->assertCount(1, $suyos);

        $this->comoEmpresa($segunda);
        $ajenos = (new MenuRepository(Database::app()))->listAllItems($primera);
        $this->assertSame([], $ajenos, 'Con el contexto de otra empresa, RLS no debe devolver nada');
    }

    public function testUnPedidoNoSeLeeConElContextoDeOtraEmpresa(): void
    {
        [$primera, $sucursal, $item] = $this->empresaConMenu();
        $pedido = OrderService::createOrder(
            $primera,
            $sucursal,
            null,
            'counter',
            [new OrderLineInput($item, 1)],
        );

        [$segunda] = $this->empresaConMenu();
        $this->comoEmpresa($segunda);

        $repo = new OrderRepository(Database::app());
        $this->assertNull(
            $repo->getById($primera, $pedido->id),
            'El id de un pedido ajeno no debe devolver nada aunque se sepa'
        );
    }

    /**
     * La comprobacion que de verdad importa: aunque la consulta pase el
     * tenant equivocado —el de quien pide, no el del dueno del dato—, RLS lo
     * corta igual. Es lo que protege de un controlador que se confunda.
     */
    public function testNiSiquieraPidiendoloConElTenantPropio(): void
    {
        [$primera, $sucursal, $item] = $this->empresaConMenu();
        $pedido = OrderService::createOrder($primera, $sucursal, null, 'counter', [new OrderLineInput($item, 1)]);

        [$segunda] = $this->empresaConMenu();
        $this->comoEmpresa($segunda);

        $this->assertNull((new OrderRepository(Database::app()))->getById($segunda, $pedido->id));
    }

    public function testCadaEmpresaNumeraSusPedidosPorSuCuenta(): void
    {
        [$primera, $sucursalA, $itemA] = $this->empresaConMenu();
        $unoDeA = OrderService::createOrder($primera, $sucursalA, null, 'counter', [new OrderLineInput($itemA, 1)]);

        [$segunda, $sucursalB, $itemB] = $this->empresaConMenu();
        $this->comoEmpresa($segunda);
        $unoDeB = OrderService::createOrder($segunda, $sucursalB, null, 'counter', [new OrderLineInput($itemB, 1)]);

        // Las dos empiezan en 1: el contador es por sucursal, no global.
        $this->assertSame($unoDeA->orderNumber, $unoDeB->orderNumber);
    }

    /**
     * Los cobros cuelgan del pedido y su politica sube por ahi: si el pedido
     * no se ve, tampoco su plata. El servicio ni siquiera llega a mirarlos.
     */
    public function testLosCobrosDeUnaNoSeVenDesdeOtra(): void
    {
        [$primera, $sucursal, $item] = $this->empresaConMenu();
        $pedido = OrderService::createOrder($primera, $sucursal, null, 'counter', [new OrderLineInput($item, 1)]);
        PaymentService::registerPayment($primera, $pedido->id, 'cash', 10_000_00);

        $this->assertCount(1, PaymentService::listPayments($primera, $pedido->id));

        [$segunda] = $this->empresaConMenu();
        $this->comoEmpresa($segunda);

        $this->expectException(PaymentError::class);
        PaymentService::listPayments($segunda, $pedido->id);
    }

    /**
     * El rol de la aplicacion no puede ser dueno de las tablas: Postgres
     * saltea RLS para el dueno, asi que si lo fuera todas las pruebas de
     * arriba pasarian sin proteger nada.
     */
    public function testElRolDeLaAplicacionNoEsDuenoDeLasTablas(): void
    {
        $stmt = Database::app()->query(
            "SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tableowner = current_user"
        );

        $this->assertSame(0, (int) $stmt->fetchColumn(), 'La conexion de la aplicacion no debe ser duena de ninguna tabla');
    }
}
