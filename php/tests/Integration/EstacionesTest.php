<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Core\Database;
use App\Domain\StationRouting;
use App\Repositories\OrderRepository;
use App\Repositories\StationRepository;
use App\Services\MenuService;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\StationError;
use App\Services\StationService;

/**
 * Estaciones de preparacion contra la base (F8.1).
 *
 * Lo que solo se ve aqui: que la linea del pedido sepa de que categoria es
 * su producto —es lo que decide su estacion— y que borrar una estacion con
 * categorias encima no las deje apuntando al vacio.
 */
final class EstacionesTest extends IntegrationTestCase
{
    private string $tenantId;
    private string $branchId;
    private string $bebidas;
    private string $carnes;
    private string $cerveza;
    private string $churrasco;

    protected function setUp(): void
    {
        [$tenant, $branch] = $this->nuevaEmpresa();
        $this->tenantId = $tenant->id;
        $this->branchId = $branch->id;

        $this->bebidas = MenuService::createCategory($this->tenantId, 'Bebidas')->id;
        $this->carnes = MenuService::createCategory($this->tenantId, 'Carnes')->id;
        $this->cerveza = MenuService::createItem($this->tenantId, $this->bebidas, 'Cerveza', 8_000_00)->id;
        $this->churrasco = MenuService::createItem($this->tenantId, $this->carnes, 'Churrasco', 40_000_00)->id;
    }

    public function testUnRestauranteNuevoNoTieneEstaciones(): void
    {
        $this->assertSame([], StationService::list($this->tenantId));
    }

    public function testNoSeRepitenLosNombres(): void
    {
        StationService::create($this->tenantId, 'Barra');

        $this->expectException(StationError::class);
        $this->expectExceptionMessageMatches('/Ya hay una estacion/');
        StationService::create($this->tenantId, 'barra');
    }

    /**
     * Borrar una estacion con categorias encima dejaria esos platos sin
     * comanda propia sin que nadie se entere.
     */
    public function testNoSeBorraUnaEstacionConCategoriasEncima(): void
    {
        $barra = StationService::create($this->tenantId, 'Barra')['id'];
        StationService::assignCategory($this->tenantId, $this->bebidas, $barra);

        $this->expectException(StationError::class);
        $this->expectExceptionMessageMatches('/1 categoria/');
        StationService::delete($this->tenantId, $barra);
    }

    public function testMovidaLaCategoriaLaEstacionSeBorra(): void
    {
        $barra = StationService::create($this->tenantId, 'Barra')['id'];
        StationService::assignCategory($this->tenantId, $this->bebidas, $barra);
        StationService::assignCategory($this->tenantId, $this->bebidas, null);

        StationService::delete($this->tenantId, $barra);
        $this->assertSame([], StationService::list($this->tenantId));
    }

    /** La linea del pedido trae la categoria de su producto: es lo que la rutea. */
    public function testLaLineaDelPedidoSabeDeQueCategoriaEs(): void
    {
        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->cerveza, 1, []), new OrderLineInput($this->churrasco, 1, [])],
        );

        $categorias = array_map(static fn ($i) => $i->categoryId, $pedido->items);
        sort($categorias);
        $esperadas = [$this->bebidas, $this->carnes];
        sort($esperadas);
        $this->assertSame($esperadas, $categorias);
    }

    /** El camino completo: dos estaciones, dos comandas. */
    public function testElPedidoSeReparteEnUnaComandaPorEstacion(): void
    {
        $barra = StationService::create($this->tenantId, 'Barra')['id'];
        $plancha = StationService::create($this->tenantId, 'Plancha')['id'];
        StationService::assignCategory($this->tenantId, $this->bebidas, $barra);
        StationService::assignCategory($this->tenantId, $this->carnes, $plancha);

        $pedido = OrderService::createOrder(
            $this->tenantId,
            $this->branchId,
            null,
            'counter',
            [new OrderLineInput($this->cerveza, 2, []), new OrderLineInput($this->churrasco, 1, [])],
        );

        $repo = new StationRepository(Database::app());
        $lineas = array_map(static fn ($i) => [
            'category_id' => $i->categoryId,
            'name_snapshot' => $i->nameSnapshot,
        ], (new OrderRepository(Database::app()))->getById($this->tenantId, $pedido->id)->items);

        $comandas = StationRouting::split(
            $lineas,
            $repo->categoryRouting($this->tenantId),
            array_column($repo->listForTenant($this->tenantId, soloActivas: true), 'name', 'id'),
        );

        $this->assertSame(['Barra', 'Plancha'], array_column($comandas, 'station_name'));
        $this->assertSame('Cerveza', $comandas[0]['lines'][0]['name_snapshot']);
        $this->assertSame('Churrasco', $comandas[1]['lines'][0]['name_snapshot']);
    }

    /** Una estacion apagada deja de rutear: lo suyo vuelve a la comanda general. */
    public function testUnaEstacionApagadaDejaDeRutear(): void
    {
        $barra = StationService::create($this->tenantId, 'Barra')['id'];
        StationService::assignCategory($this->tenantId, $this->bebidas, $barra);
        StationService::update($this->tenantId, $barra, 'Barra', isActive: false);

        $this->assertSame([], (new StationRepository(Database::app()))->categoryRouting($this->tenantId));
    }

    /** Una estacion de otra empresa no existe desde aqui. */
    public function testNoSeAsignaUnaEstacionAjena(): void
    {
        [$otro] = $this->nuevaEmpresa();
        $ajena = StationService::create($otro->id, 'Barra ajena')['id'];
        $this->comoEmpresa($this->tenantId);

        $this->expectException(StationError::class);
        $this->expectExceptionMessageMatches('/no existe para este tenant/');
        StationService::assignCategory($this->tenantId, $this->bebidas, $ajena);
    }
}
