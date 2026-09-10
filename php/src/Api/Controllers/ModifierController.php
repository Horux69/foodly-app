<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Core\Money;
use App\Domain\ModifierGroupRules;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Services\MenuError;
use App\Services\ModifierService;

/**
 * Grupos de modificadores desde la web: lo que antes solo se podia poblar
 * entrando a la base.
 *
 * Va bajo /menu porque es parte de la carta, y se autoriza con 'menu.edit'
 * como el resto de su administracion.
 */
final class ModifierController
{
    private static function modifierOut(Modifier $m): array
    {
        return [
            'id' => $m->id,
            'group_id' => $m->groupId,
            'name' => $m->name,
            'price_delta' => Money::toDecimalString($m->priceDeltaCents),
            'is_available' => $m->isAvailable,
        ];
    }

    private static function groupOut(ModifierGroup $g, int $usedBy): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'min_select' => $g->minSelect,
            'max_select' => $g->maxSelect,
            'is_required' => $g->isRequired,
            // La frase la arma el dominio: la pantalla la muestra tal cual, y
            // asi la web y cualquier otro cliente dicen lo mismo.
            'rule' => ModifierGroupRules::describe($g->minSelect, $g->maxSelect, $g->isRequired),
            'used_by_items' => $usedBy,
            'modifiers' => array_map(self::modifierOut(...), $g->modifiers),
        ];
    }

    /** @return array{0: int, 1: int, 2: bool} min, max e is_required del cuerpo */
    private static function groupShape(array $body): array
    {
        $isRequired = array_key_exists('is_required', $body) && Request::bool($body, 'is_required');
        return [
            Request::int($body, 'min_select', default: $isRequired ? 1 : 0, min: 0),
            Request::int($body, 'max_select', default: 1, min: 0),
            $isRequired,
        ];
    }

    public static function listGroups(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        [$groups, $usage] = ModifierService::listGroups($ctx->tenantId);

        return array_map(
            static fn (ModifierGroup $g) => self::groupOut($g, $usage[$g->id] ?? 0),
            $groups
        );
    }

    public static function createGroup(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();
        [$min, $max, $required] = self::groupShape($body);

        try {
            $group = ModifierService::createGroup(
                $ctx->tenantId,
                Request::string($body, 'name', 1, ModifierGroupRules::NOMBRE_MAX),
                $min,
                $max,
                $required,
            );
        } catch (MenuError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::groupOut($group, 0), 201);
    }

    public static function updateGroup(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();
        [$min, $max, $required] = self::groupShape($body);

        try {
            $group = ModifierService::updateGroup(
                $ctx->tenantId,
                $params['group_id'],
                Request::string($body, 'name', 1, ModifierGroupRules::NOMBRE_MAX),
                $min,
                $max,
                $required,
            );
        } catch (MenuError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return self::groupOut($group, 0);
    }

    public static function deleteGroup(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        try {
            ModifierService::deleteGroup($ctx->tenantId, $params['group_id']);
        } catch (MenuError $e) {
            throw new ApiException(422, $e->getMessage());
        }
        return new JsonResponse(null, 204);
    }

    // ---------- Opciones ----------

    /** El delta puede ser negativo: "sin queso" descuenta. */
    private static function priceDeltaCents(array $body): int
    {
        return Money::fromDecimalString(Request::decimalString($body, 'price_delta', '0'));
    }

    public static function createModifier(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();

        try {
            $modifier = ModifierService::createModifier(
                $ctx->tenantId,
                $params['group_id'],
                Request::string($body, 'name', 1, 100),
                self::priceDeltaCents($body),
                !array_key_exists('is_available', $body) || Request::bool($body, 'is_available'),
            );
        } catch (MenuError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::modifierOut($modifier), 201);
    }

    public static function updateModifier(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();

        try {
            $modifier = ModifierService::updateModifier(
                $ctx->tenantId,
                $params['modifier_id'],
                Request::string($body, 'name', 1, 100),
                self::priceDeltaCents($body),
                !array_key_exists('is_available', $body) || Request::bool($body, 'is_available'),
            );
        } catch (MenuError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return self::modifierOut($modifier);
    }

    public static function deleteModifier(array $params): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        try {
            ModifierService::deleteModifier($ctx->tenantId, $params['modifier_id']);
        } catch (MenuError $e) {
            throw new ApiException(422, $e->getMessage());
        }
        return new JsonResponse(null, 204);
    }

    // ---------- Asignacion ----------

    /**
     * Reemplaza los grupos de un producto. El orden de la lista es el orden en
     * que se van a pedir: primero el termino de la carne, despues las
     * adiciones.
     */
    public static function setItemGroups(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'menu.edit');
        $body = Request::json();

        $groupIds = Request::stringList($body, 'group_ids');
        foreach ($groupIds as $id) {
            if (!Request::isUuid($id)) {
                throw new ApiException(422, "'group_ids' debe ser una lista de UUID");
            }
        }

        try {
            $quedaron = ModifierService::setItemGroups($ctx->tenantId, $params['item_id'], $groupIds);
        } catch (MenuError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return ['item_id' => $params['item_id'], 'group_ids' => $quedaron];
    }
}
