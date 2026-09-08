<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\MenuPricing;
use App\Models\BranchMenuOverride;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Repositories\BranchRepository;
use App\Repositories\MenuRepository;
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
    public static function getCatalog(string $tenantId, ?string $branchId = null): array
    {
        $repo = new MenuRepository(Database::app());
        return [
            $repo->listAllCategories($tenantId),
            $repo->listAllItems($tenantId),
            $branchId === null ? [] : $repo->getOverridesForBranch($branchId),
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

        $repo->updateItem($itemId, $changes);
        return $repo->getItem($tenantId, $itemId);
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
