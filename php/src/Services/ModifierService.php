<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\ModifierGroupError;
use App\Domain\ModifierGroupRules;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Repositories\MenuRepository;
use App\Repositories\ModifierRepository;

/**
 * Grupos de modificadores y su asignacion a productos.
 *
 * La validacion de la forma del grupo esta en Domain\ModifierGroupRules; aqui
 * quedan las reglas que necesitan mirar la base: que no se borre lo que esta
 * en uso y que no se borre lo que ya se vendio.
 */
final class ModifierService
{
    /**
     * @return array{0: ModifierGroup[], 1: array<string, int>} grupos y cuantos productos usa cada uno
     */
    public static function listGroups(string $tenantId): array
    {
        $repo = new ModifierRepository(Database::app());
        return [$repo->listGroups($tenantId), $repo->usageByGroup($tenantId)];
    }

    public static function createGroup(
        string $tenantId,
        string $name,
        int $minSelect,
        int $maxSelect,
        bool $isRequired,
    ): ModifierGroup {
        self::validarGrupo($name, $minSelect, $maxSelect, $isRequired);
        return (new ModifierRepository(Database::app()))
            ->createGroup($tenantId, trim($name), $minSelect, $maxSelect, $isRequired);
    }

    public static function updateGroup(
        string $tenantId,
        string $groupId,
        string $name,
        int $minSelect,
        int $maxSelect,
        bool $isRequired,
    ): ModifierGroup {
        $repo = new ModifierRepository(Database::app());
        self::grupo($repo, $tenantId, $groupId);
        self::validarGrupo($name, $minSelect, $maxSelect, $isRequired);

        $repo->updateGroup($groupId, trim($name), $minSelect, $maxSelect, $isRequired);
        return $repo->getGroup($tenantId, $groupId);
    }

    /**
     * Borrar un grupo es raro y casi siempre un error: se rechaza mientras
     * este asignado a algun producto, diciendo a cuantos, y se rechaza si
     * alguna de sus opciones ya se vendio — ahi la salida es dejar de
     * asignarlo, no borrarlo, porque los pedidos viejos apuntan a esas filas.
     */
    public static function deleteGroup(string $tenantId, string $groupId): void
    {
        $repo = new ModifierRepository(Database::app());
        self::grupo($repo, $tenantId, $groupId);

        $enUso = $repo->usageByGroup($tenantId)[$groupId] ?? 0;
        if ($enUso > 0) {
            throw new MenuError(
                "El grupo lo usan {$enUso} producto(s): quitaselo primero desde cada uno"
            );
        }
        if ($repo->groupWasSold($groupId)) {
            throw new MenuError(
                'Alguna opcion de este grupo ya se vendio, asi que no se puede borrar: los pedidos anteriores la referencian'
            );
        }

        $repo->deleteGroup($groupId);
    }

    // ---------- Opciones ----------

    public static function createModifier(
        string $tenantId,
        string $groupId,
        string $name,
        int $priceDeltaCents,
        bool $isAvailable,
    ): Modifier {
        $repo = new ModifierRepository(Database::app());
        self::grupo($repo, $tenantId, $groupId);
        self::validarNombreDeOpcion($name);

        return $repo->createModifier($groupId, trim($name), $priceDeltaCents, $isAvailable);
    }

    public static function updateModifier(
        string $tenantId,
        string $modifierId,
        string $name,
        int $priceDeltaCents,
        bool $isAvailable,
    ): Modifier {
        $repo = new ModifierRepository(Database::app());
        if ($repo->getModifier($tenantId, $modifierId) === null) {
            throw new MenuError('La opcion no existe para este tenant');
        }
        self::validarNombreDeOpcion($name);

        // El nombre se congela en el pedido (`order_item_modifiers.name_snapshot`),
        // asi que renombrar aqui no reescribe lo ya vendido.
        $repo->updateModifier($modifierId, trim($name), $priceDeltaCents, $isAvailable);
        return $repo->getModifier($tenantId, $modifierId);
    }

    public static function deleteModifier(string $tenantId, string $modifierId): void
    {
        $repo = new ModifierRepository(Database::app());
        $modifier = $repo->getModifier($tenantId, $modifierId);
        if ($modifier === null) {
            throw new MenuError('La opcion no existe para este tenant');
        }
        if ($repo->modifierWasSold($modifierId)) {
            throw new MenuError(
                "'{$modifier->name}' ya se vendio, asi que no se puede borrar: marcala como no disponible y deja de ofrecerse"
            );
        }

        $repo->deleteModifier($modifierId);
    }

    // ---------- Asignacion ----------

    /**
     * Reemplaza los grupos de un producto, en el orden dado.
     *
     * @param string[] $groupIds
     * @return string[] los grupos que quedaron, en orden
     */
    public static function setItemGroups(string $tenantId, string $itemId, array $groupIds): array
    {
        $pdo = Database::app();
        if ((new MenuRepository($pdo))->getItem($tenantId, $itemId) === null) {
            throw new MenuError('El producto no existe para este tenant');
        }

        // Sin duplicados: la llave primaria es (item_id, group_id) y un
        // segundo INSERT del mismo grupo abortaria la transaccion entera.
        $unicos = array_values(array_unique($groupIds));

        $repo = new ModifierRepository($pdo);
        $existentes = $repo->existingGroupIds($tenantId, $unicos);
        $desconocidos = array_values(array_diff($unicos, $existentes));
        if ($desconocidos !== []) {
            throw new MenuError('Grupos desconocidos: ' . implode(', ', $desconocidos));
        }

        $repo->setItemGroups($itemId, $unicos);
        return $unicos;
    }

    // ---------- Auxiliares ----------

    private static function grupo(ModifierRepository $repo, string $tenantId, string $groupId): ModifierGroup
    {
        $group = $repo->getGroup($tenantId, $groupId);
        if ($group === null) {
            throw new MenuError('El grupo no existe para este tenant');
        }
        return $group;
    }

    private static function validarGrupo(string $name, int $minSelect, int $maxSelect, bool $isRequired): void
    {
        try {
            ModifierGroupRules::validate($name, $minSelect, $maxSelect, $isRequired);
        } catch (ModifierGroupError $e) {
            throw new MenuError($e->getMessage());
        }
    }

    private static function validarNombreDeOpcion(string $name): void
    {
        if (trim($name) === '') {
            throw new MenuError('La opcion necesita un nombre');
        }
    }
}
