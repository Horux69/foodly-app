<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Repositories\MenuRepository;
use App\Repositories\OrderRepository;
use App\Services\MenuError;
use App\Services\MenuService;
use App\Services\OrderLineInput;
use App\Services\OrderService;

/**
 * Combos: varios productos completos a precio de paquete.
 *
 * Lo que se prueba aqui y no en el dominio es lo que solo existe cuando hay
 * base: que la composicion se congela en el pedido, que archivar un producto
 * que un combo lleva dentro no lo vacia en silencio, y que un ciclo se
 * detecta con lo que hay guardado y no solo con lo que se manda.
 */
final class CombosTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $hamburguesa;
    private string $papas;
    private string $gaseosa;
    private string $combo;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa();
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $categoria = MenuService::createCategory($this->tenantId, 'Carta');
        $crear = fn (string $nombre, int $cents) => MenuService::createItem(
            $this->tenantId,
            $categoria->id,
            $nombre,
            $cents,
        )->id;

        $this->hamburguesa = $crear('Hamburguesa', 22_000_00);
        $this->papas = $crear('Papas', 8_000_00);
        $this->gaseosa = $crear('Gaseosa', 5_000_00);
        $this->combo = $crear('Combo del dia', 28_000_00);
    }

    /** @param array<int, array{item_id: string, quantity: int}> $componentes */
    private function componer(array $componentes, ?string $comboId = null): array
    {
        return MenuService::setComponents($this->tenantId, $comboId ?? $this->combo, $componentes);
    }

    private function armarComboBasico(): void
    {
        $this->componer([
            ['item_id' => $this->hamburguesa, 'quantity' => 1],
            ['item_id' => $this->papas, 'quantity' => 1],
        ]);
    }

    public function testSeGuardaLoQueLlevaEnElOrdenQueSeMando(): void
    {
        $guardado = $this->componer([
            ['item_id' => $this->gaseosa, 'quantity' => 2],
            ['item_id' => $this->hamburguesa, 'quantity' => 1],
        ]);

        $this->assertSame(['Gaseosa', 'Hamburguesa'], array_column($guardado, 'name'));
        $this->assertSame([2, 1], array_column($guardado, 'quantity'));
    }

    /** Un combo se cobra por su propio precio: lo que lleva no suma. */
    public function testElPedidoCobraElPrecioDelPaqueteYNoLaSumaDeSusPartes(): void
    {
        $this->armarComboBasico();

        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->combo, 1, [])],
        );

        $this->assertSame(28_000_00, $pedido->totalCents);
    }

    /**
     * La composicion se congela: cambiar el combo despues no toca el pedido
     * ya tomado, igual que el precio y el nombre (principio 8).
     */
    public function testLoQueLlevabaSeCongelaEnLaLineaDelPedido(): void
    {
        $this->armarComboBasico();

        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->combo, 1, [])],
        );

        // El combo cambia de contenido despues de la venta.
        $this->componer([
            ['item_id' => $this->gaseosa, 'quantity' => 1],
            ['item_id' => $this->papas, 'quantity' => 1],
        ]);

        $releido = (new OrderRepository(Database::app()))->getById($this->tenantId, $pedido->id);
        $componentes = $releido->items[0]->components;

        $this->assertSame(['Hamburguesa', 'Papas'], array_map(fn ($c) => $c->nameSnapshot, $componentes));
    }

    /** Dos combos son dos de cada cosa: lo multiplica el dominio al congelar. */
    public function testDosCombosSonDosDeCadaCosa(): void
    {
        $this->componer([
            ['item_id' => $this->hamburguesa, 'quantity' => 1],
            ['item_id' => $this->gaseosa, 'quantity' => 2],
        ]);

        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->combo, 3, [])],
        );

        $releido = (new OrderRepository(Database::app()))->getById($this->tenantId, $pedido->id);
        $this->assertSame([3, 6], array_map(fn ($c) => $c->quantity, $releido->items[0]->components));
    }

    /**
     * Archivar la gaseosa vaciaria el combo en silencio: se seguiria
     * vendiendo al mismo precio con una cosa menos.
     */
    public function testNoSeArchivaUnProductoQueUnComboLlevaDentro(): void
    {
        $this->componer([
            ['item_id' => $this->hamburguesa, 'quantity' => 1],
            ['item_id' => $this->gaseosa, 'quantity' => 1],
        ]);

        $this->expectException(MenuError::class);
        $this->expectExceptionMessageMatches('/Combo del dia/');
        MenuService::updateItem($this->tenantId, $this->gaseosa, ['is_archived' => true]);
    }

    /** Sacado del combo, ya se puede archivar: la regla no lo deja atrapado. */
    public function testQuitadoDelComboSeArchivaSinProblema(): void
    {
        $this->componer([
            ['item_id' => $this->hamburguesa, 'quantity' => 1],
            ['item_id' => $this->gaseosa, 'quantity' => 1],
        ]);
        $this->armarComboBasico();

        $item = MenuService::updateItem($this->tenantId, $this->gaseosa, ['is_archived' => true]);
        $this->assertTrue($item->isArchived);
    }

    /**
     * El ciclo indirecto se detecta con la composicion guardada, no solo con
     * la que llega en la peticion: A lleva B, y B no puede llevar A.
     */
    public function testUnComboNoPuedeContenerAOtroQueLoContiene(): void
    {
        // El combo del dia lleva la hamburguesa y otro combo.
        $otro = MenuService::createItem(
            $this->tenantId,
            MenuService::createCategory($this->tenantId, 'Combos')->id,
            'Combo grande',
            40_000_00,
        )->id;

        $this->componer([
            ['item_id' => $this->hamburguesa, 'quantity' => 1],
            ['item_id' => $otro, 'quantity' => 1],
        ]);

        $this->expectException(MenuError::class);
        $this->expectExceptionMessageMatches('/a si mismo/');
        $this->componer([
            ['item_id' => $this->papas, 'quantity' => 1],
            ['item_id' => $this->combo, 'quantity' => 1],
        ], $otro);
    }

    /** Un producto de otra empresa no existe desde aqui, aunque la FK lo aceptara. */
    public function testNoSePuedeMeterUnProductoDeOtraEmpresa(): void
    {
        [$otroTenant] = $this->nuevaEmpresa();
        $ajeno = MenuService::createItem(
            $otroTenant->id,
            MenuService::createCategory($otroTenant->id, 'Carta')->id,
            'Producto ajeno',
            1_000_00,
        )->id;

        $this->comoEmpresa($this->tenantId);

        $this->expectException(MenuError::class);
        $this->componer([
            ['item_id' => $this->hamburguesa, 'quantity' => 1],
            ['item_id' => $ajeno, 'quantity' => 1],
        ]);
    }

    /** Vaciar la lista lo devuelve a producto suelto. */
    public function testUnaListaVaciaLoDevuelveAProductoSuelto(): void
    {
        $this->armarComboBasico();
        $this->assertSame([], $this->componer([]));

        $repo = new MenuRepository(Database::app());
        $this->assertArrayNotHasKey($this->combo, $repo->componentsByItem($this->tenantId));
    }
}
