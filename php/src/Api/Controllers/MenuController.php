<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Core\Money;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Services\MenuCategoryView;
use App\Services\MenuError;
use App\Services\MenuService;

/**
 * Equivalente PHP de app/api/v1/menu.py. Los importes salen como cadena
 * decimal ("18000.00"), igual que serializa Pydantic un Decimal: el
 * frontend ya los pasa por Number() y no necesita ningun cambio.
 */
final class MenuController
{
    /** @param ModifierGroup[] $groups */
    private static function modifierGroupsOut(array $groups): array
    {
        return array_map(static fn (ModifierGroup $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'min_select' => $g->minSelect,
            'max_select' => $g->maxSelect,
            'is_required' => $g->isRequired,
            'modifiers' => array_map(static fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'price_delta' => Money::toDecimalString($m->priceDeltaCents),
                'is_available' => $m->isAvailable,
            ], $g->modifiers),
        ], $groups);
    }

    public static function getMenu(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.view');
        $categories = MenuService::getMenu($ctx->tenantId, Deps::activeBranchIdOrNull($ctx));

        return array_map(static fn (MenuCategoryView $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'items' => array_map(static fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'description' => $i->description,
                'price' => Money::toDecimalString($i->priceCents),
                'is_available' => $i->isAvailable,
                'modifier_groups' => self::modifierGroupsOut($i->modifierGroups),
            ], $c->items),
        ], $categories);
    }

    /** Catalogo crudo para administrar: precio base sin overrides de sucursal y con los archivados incluidos. */
    public static function getCatalog(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.view');
        [$categories, $items] = MenuService::getCatalog($ctx->tenantId);

        return [
            'categories' => array_map(static fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'sort_order' => $c->sortOrder,
                'is_active' => $c->isActive,
            ], $categories),
            'items' => array_map(static fn (MenuItem $i) => [
                'id' => $i->id,
                'category_id' => $i->categoryId,
                'name' => $i->name,
                'description' => $i->description,
                'base_price' => Money::toDecimalString($i->basePriceCents),
                'tax_rate_id' => $i->taxRateId,
                'prep_minutes' => $i->prepMinutes,
                'is_available' => $i->isAvailable,
                'is_archived' => $i->isArchived,
                'sort_order' => $i->sortOrder,
            ], $items),
        ];
    }

    public static function createCategory(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();
        $category = MenuService::createCategory(
            $ctx->tenantId,
            Request::string($body, 'name', 1, 100),
            array_key_exists('sort_order', $body) ? Request::int($body, 'sort_order') : 0,
        );

        return new JsonResponse(
            ['id' => $category->id, 'name' => $category->name, 'sort_order' => $category->sortOrder],
            201,
        );
    }

    /** Importe no negativo, ya normalizado a dos decimales. */
    private static function priceCents(array $body, string $key): int
    {
        $cents = Money::fromDecimalString(Request::decimalString($body, $key));
        if ($cents < 0) {
            throw new ApiException(422, "'{$key}' no puede ser negativo");
        }
        return $cents;
    }

    public static function createItem(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();

        try {
            $item = MenuService::createItem(
                $ctx->tenantId,
                Request::uuid($body, 'category_id'),
                Request::string($body, 'name', 1, 150),
                self::priceCents($body, 'base_price'),
                Request::optionalString($body, 'description'),
                array_key_exists('prep_minutes', $body) && $body['prep_minutes'] !== null
                    ? Request::int($body, 'prep_minutes', min: 0)
                    : null,
                Request::optionalUuid($body, 'tax_rate_id'),
            );
        } catch (MenuError $e) {
            throw new ApiException(404, $e->getMessage());
        }

        return new JsonResponse([
            'id' => $item->id,
            'name' => $item->name,
            'base_price' => Money::toDecimalString($item->basePriceCents),
            'tax_rate_id' => $item->taxRateId,
        ], 201);
    }

    public static function updateItem(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();

        // Se mira que claves vienen, no cuales son null: hay que poder
        // distinguir "no lo mandes" de "ponlo en null" (p.ej. dejar un
        // producto exento de impuesto), igual que el exclude_unset de Pydantic.
        $changes = [];
        if (array_key_exists('category_id', $body)) {
            $changes['category_id'] = Request::uuid($body, 'category_id');
        }
        if (array_key_exists('name', $body)) {
            $changes['name'] = Request::string($body, 'name', 1, 150);
        }
        if (array_key_exists('base_price', $body)) {
            $changes['base_price'] = Money::toDecimalString(self::priceCents($body, 'base_price'));
        }
        if (array_key_exists('description', $body)) {
            $changes['description'] = Request::optionalString($body, 'description');
        }
        if (array_key_exists('prep_minutes', $body)) {
            $changes['prep_minutes'] = $body['prep_minutes'] === null ? null : Request::int($body, 'prep_minutes', min: 0);
        }
        if (array_key_exists('tax_rate_id', $body)) {
            $changes['tax_rate_id'] = Request::optionalUuid($body, 'tax_rate_id');
        }
        if (array_key_exists('sort_order', $body)) {
            $changes['sort_order'] = Request::int($body, 'sort_order');
        }
        if (array_key_exists('is_archived', $body)) {
            $changes['is_archived'] = Request::bool($body, 'is_archived');
        }

        if ($changes === []) {
            throw new ApiException(400, 'No hay nada que actualizar');
        }

        try {
            $item = MenuService::updateItem($ctx->tenantId, $params['item_id'], $changes);
        } catch (MenuError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return [
            'id' => $item->id,
            'name' => $item->name,
            'base_price' => Money::toDecimalString($item->basePriceCents),
            'tax_rate_id' => $item->taxRateId,
            'is_archived' => $item->isArchived,
        ];
    }

    public static function updateItemAvailability(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.availability');
        $body = Request::json();

        try {
            $item = MenuService::setItemAvailability(
                $ctx->tenantId,
                $params['item_id'],
                Request::bool($body, 'is_available'),
            );
        } catch (MenuError $e) {
            throw new ApiException(404, $e->getMessage());
        }

        return ['id' => $item->id, 'is_available' => $item->isAvailable];
    }

    public static function setBranchOverride(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();

        $price = array_key_exists('price', $body) && $body['price'] !== null
            ? self::priceCents($body, 'price')
            : null;
        $isAvailable = array_key_exists('is_available', $body) && $body['is_available'] !== null
            ? Request::bool($body, 'is_available')
            : null;

        try {
            $override = MenuService::setBranchOverride(
                $ctx->tenantId,
                Request::uuid($body, 'branch_id'),
                $params['item_id'],
                $price,
                $isAvailable,
            );
        } catch (MenuError $e) {
            throw new ApiException(404, $e->getMessage());
        }

        return [
            'branch_id' => $override->branchId,
            'menu_item_id' => $override->menuItemId,
            'price' => $override->priceCents === null ? null : Money::toDecimalString($override->priceCents),
            'is_available' => $override->isAvailable,
        ];
    }
}
