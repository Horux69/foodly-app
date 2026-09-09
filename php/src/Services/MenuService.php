<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\ComboError;
use App\Domain\ComboRules;
use App\Domain\MenuPricing;
use App\Models\BranchMenuOverride;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Repositories\BranchRepository;
use App\Repositories\MenuRepository;
use App\Repositories\ModifierRepository;
use App\Repositories\TaxRateRepository;

final class MenuService
{
    /**
     * Menu con precio y disponibilidad efectivos para la sucursal dada.
     *
     * Si no hay sucursal (p.ej. un admin de tenant sin sucursal fija), se
     * devuelven los valores base sin resolver overrides.
     *
     * @return MenuCategoryView[]
     */
    public static function getMenu(string $tenantId, ?string $branchId): array
    {
        $pdo = Database::app();
        $repo = new MenuRepository($pdo);

        $categories = $repo->listCategoriesWithItems($tenantId);
        $overrides = $branchId !== null ? $repo->getOverridesForBranch($branchId) : [];
        // Que lleva cada combo, para que quien vende pueda decirlo sin
        // abrir otra pantalla.
        $components = $repo->componentsByItem($tenantId);

        $result = [];
        foreach ($categories as $category) {
            $items = [];
            foreach ($category->items as $item) {
                if ($item->isArchived) {
                    continue;
                }
                $override = $overrides[$item->id] ?? null;
                $effective = MenuPricing::resolveEffectiveMenuItem(
                    $item->basePriceCents,
                    $item->isAvailable,
                    $override?->priceCents,
                    $override?->isAvailable,
                );
                $items[] = new MenuItemView(
                    id: $item->id,
                    name: $item->name,
                    description: $item->description,
                    priceCents: $effective->priceCents,
                    isAvailable: $effective->isAvailable,
                    modifierGroups: $item->modifierGroups,
                    components: $components[$item->id] ?? [],
                );
            }
            $result[] = new MenuCategoryView($category->id, $category->name, $items);
        }
        return $result;
    }

    /**
     * Catalogo para administrar, no para vender.
     *
     * @return array{0: MenuCategory[], 1: MenuItem[]}
     */
    /**
     * Catalogo de administracion, con los ajustes de la sucursal que se este
     * mirando si hay una.
     *
     * Los overrides viajan aparte del producto y no mezclados en su precio:
     * la pantalla necesita ver las dos cifras —el precio base y el de esta
     * sucursal— para poder decidir. Quien resuelve cual manda es
     * Domain\MenuPricing, en un solo lugar.
     *
     * @return array{0: MenuCategory[], 1: MenuItem[], 2: array<string, \App\Models\BranchMenuOverride>}
     */
    /**
     * @return array{0: MenuCategory[], 1: MenuItem[], 2: array<string, BranchMenuOverride>, 3: array<string, string[]>,
     *         4: array<string, array<int, array{item_id: string, name: string, quantity: int}>>}
     *         categorias, productos, overrides de la sucursal, grupos de
     *         modificadores por producto y componentes de los que son combo
     */
    public static function getCatalog(string $tenantId, ?string $branchId = null): array
    {
        $pdo = Database::app();
        $repo = new MenuRepository($pdo);
        return [
            $repo->listAllCategories($tenantId),
            $repo->listAllItems($tenantId),
            $branchId === null ? [] : $repo->getOverridesForBranch($branchId),
            // Una consulta para todos los productos, no una por fila: la
            // pantalla necesita saber que grupos tiene cada uno para poder
            // ofrecer cambiarlos.
            (new ModifierRepository($pdo))->groupIdsByItem($tenantId),
            // Igual que los grupos: una consulta para todo el catalogo. Solo
            // los combos aparecen aqui, y son pocos.
            $repo->componentsByItem($tenantId),
        ];
    }

    public static function createCategory(string $tenantId, string $name, int $sortOrder = 0): MenuCategory
    {
        return (new MenuRepository(Database::app()))->createCategory($tenantId, $name, $sortOrder);
    }

    /**
     * Sin impuesto explicito se hereda el default del tenant: un producto
     * nuevo debe salir cobrando lo que cobra el restaurante, no exento.
     */
    private static function resolveTaxRateId(string $tenantId, ?string $taxRateId): ?string
    {
        $taxes = new TaxRateRepository(Database::app());
        if ($taxRateId === null) {
            return $taxes->getDefault($tenantId)?->id;
        }
        if ($taxes->get($tenantId, $taxRateId) === null) {
            throw new MenuError('El impuesto no existe para este tenant');
        }
        return $taxRateId;
    }

    public static function createItem(
        string $tenantId,
        string $categoryId,
        string $name,
        int $basePriceCents,
        ?string $description = null,
        ?int $prepMinutes = null,
        ?string $taxRateId = null,
    ): MenuItem {
        $repo = new MenuRepository(Database::app());
        $category = $repo->getCategory($tenantId, $categoryId);
        if ($category === null) {
            throw new MenuError('La categoria no existe para este tenant');
        }

        return $repo->createItem(
            $category->id,
            $name,
            $basePriceCents,
            self::resolveTaxRateId($tenantId, $taxRateId),
            $description,
            $prepMinutes,
        );
    }

    /**
     * Edita un producto. El precio nuevo solo aplica a pedidos futuros: los
     * ya tomados guardan su propio unit_price congelado.
     *
     * @param array<string, mixed> $changes columnas presentes en el parche; la
     *        ausencia de una clave significa "no tocar", y tax_rate_id en null
     *        significa dejar el producto exento.
     */
    public static function updateItem(string $tenantId, string $itemId, array $changes): MenuItem
    {
        $repo = new MenuRepository(Database::app());
        $item = $repo->getItem($tenantId, $itemId);
        if ($item === null) {
            throw new MenuError('El producto no existe para este tenant');
        }

        if (array_key_exists('category_id', $changes)
            && $repo->getCategory($tenantId, $changes['category_id']) === null) {
            throw new MenuError('La categoria no existe para este tenant');
        }

        // Solo se valida cuando viene un id: null es intencional (exento) y no
        // debe sustituirse por el default del tenant.
        if (($changes['tax_rate_id'] ?? null) !== null) {
            $changes['tax_rate_id'] = self::resolveTaxRateId($tenantId, $changes['tax_rate_id']);
        }

        // Archivar un producto que un combo lleva dentro vaciaria ese combo
        // en silencio: se seguiria vendiendo al mismo precio con una cosa
        // menos, y nadie se enteraria hasta que alguien reclamara su gaseosa.
        if (($changes['is_archived'] ?? false) === true && !$item->isArchived) {
            $combos = $repo->combosThatUse($itemId);
            if ($combos !== []) {
                throw new MenuError(
                    "'{$item->name}' esta dentro de " . count($combos) . ' combo(s): '
                    . implode(', ', $combos) . '. Quitalo de ahi antes de archivarlo.'
                );
            }
        }

        $repo->updateItem($itemId, $changes);
        return $repo->getItem($tenantId, $itemId);
    }

    /**
     * Define que lleva un combo. Una lista vacia lo devuelve a producto suelto.
     *
     * @param array<int, array{item_id: string, quantity: int}> $componentes
     * @return array<int, array{item_id: string, name: string, quantity: int}>
     */
    public static function setComponents(string $tenantId, string $itemId, array $componentes): array
    {
        $repo = new MenuRepository(Database::app());
        $combo = $repo->getItem($tenantId, $itemId);
        if ($combo === null) {
            throw new MenuError('El producto no existe para este tenant');
        }

        // Los ids se resuelven contra el catalogo del tenant antes de tocar
        // nada: uno de otra empresa no existe desde aqui, aunque la clave
        // foranea de la base lo aceptara.
        foreach ($componentes as $componente) {
            if ($repo->getItem($tenantId, $componente['item_id']) === null) {
                throw new MenuError("El producto {$componente['item_id']} no existe para este tenant");
            }
        }

        if ($componentes !== []) {
            try {
                ComboRules::validate($itemId, $componentes, self::composicionActual($repo, $tenantId));
            } catch (ComboError $e) {
                throw new MenuError($e->getMessage());
            }
        }

        $repo->setComponents($itemId, $componentes);
        return $repo->componentsOf($itemId);
    }

    /**
     * Que lleva hoy cada combo del tenant, solo con los ids: es lo que
     * ComboRules necesita para descubrir un ciclo indirecto.
     *
     * @return array<string, string[]>
     */
    private static function composicionActual(MenuRepository $repo, string $tenantId): array
    {
        $result = [];
        foreach ($repo->componentsByItem($tenantId) as $parentId => $componentes) {
            $result[$parentId] = array_column($componentes, 'item_id');
        }
        return $result;
    }

    public static function setItemAvailability(string $tenantId, string $itemId, bool $isAvailable): MenuItem
    {
        $repo = new MenuRepository(Database::app());
        if ($repo->getItem($tenantId, $itemId) === null) {
            throw new MenuError('El producto no existe para este tenant');
        }
        $repo->setItemAvailability($itemId, $isAvailable);
        return $repo->getItem($tenantId, $itemId);
    }

    /**
     * Fija —o quita— el ajuste de un producto en una sucursal.
     *
     * Un `null` no es "no cambies": es "esta sucursal no ajusta eso", y el
     * producto vuelve a regirse por su precio o su disponibilidad base. Es la
     * forma de deshacer un ajuste sin borrar la fila.
     */
    public static function setBranchOverride(
        string $tenantId,
        string $branchId,
        string $itemId,
        ?int $priceCents,
        ?bool $isAvailable,
    ): BranchMenuOverride {
        $pdo = Database::app();
        $repo = new MenuRepository($pdo);

        if ($repo->getItem($tenantId, $itemId) === null) {
            throw new MenuError('El producto no existe para este tenant');
        }
        if ((new BranchRepository($pdo))->get($tenantId, $branchId) === null) {
            throw new MenuError('La sucursal no existe para este tenant');
        }

        return $repo->upsertBranchOverride($branchId, $itemId, $priceCents, $isAvailable);
    }
}
