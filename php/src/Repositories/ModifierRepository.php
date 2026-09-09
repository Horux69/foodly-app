<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Money;
use App\Core\Row;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use PDO;

/**
 * Grupos de modificadores, sus opciones, y a que productos estan asignados.
 *
 * Aparte de MenuRepository —que lleva categorias, productos y precios por
 * sucursal— porque es otro agregado con su propio CRUD completo, y juntarlos
 * dejaria un archivo que nadie recorre entero.
 *
 * El aislamiento entre empresas lo garantiza RLS (`modifier_groups.tenant_id`
 * y, para las opciones, la politica que sube por `group_id`), pero las
 * consultas filtran igual por tenant: la red de seguridad no es excusa para
 * escribir una consulta que sin ella cruzaria empresas.
 */
final class ModifierRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Todos los grupos del tenant con sus opciones.
     *
     * Dos consultas y el armado en PHP, no una por grupo.
     *
     * @return ModifierGroup[]
     */
    public function listGroups(string $tenantId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM modifier_groups WHERE tenant_id = :tenant_id ORDER BY name');
        $stmt->execute(['tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        $porGrupo = $this->modifiersByGroup(array_column($rows, 'id'));
        return array_map(
            static fn (array $row) => ModifierGroup::fromRow($row, $porGrupo[$row['id']] ?? []),
            $rows
        );
    }

    /**
     * @param string[] $groupIds
     * @return array<string, Modifier[]>
     */
    private function modifiersByGroup(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM modifiers WHERE group_id IN ({$placeholders}) ORDER BY price_delta, name"
        );
        $stmt->execute(array_values($groupIds));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['group_id']][] = Modifier::fromRow($row);
        }
        return $result;
    }

    /**
     * Cuantos productos usa cada grupo, indexado por group_id.
     *
     * Es lo que impide borrar un grupo que esta en uso y lo que la pantalla
     * muestra al lado de cada uno.
     *
     * @return array<string, int>
     */
    public function usageByGroup(string $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT img.group_id, count(*) AS total
               FROM item_modifier_groups img
               JOIN modifier_groups g ON g.id = img.group_id
              WHERE g.tenant_id = :tenant_id
           GROUP BY img.group_id'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['group_id']] = (int) $row['total'];
        }
        return $result;
    }

    public function getGroup(string $tenantId, string $groupId): ?ModifierGroup
    {
        $stmt = $this->pdo->prepare('SELECT * FROM modifier_groups WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute(['id' => $groupId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return ModifierGroup::fromRow($row, $this->modifiersByGroup([$row['id']])[$row['id']] ?? []);
    }

    /** @param string[] $groupIds @return string[] los que existen en el tenant */
    public function existingGroupIds(string $tenantId, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id FROM modifier_groups WHERE tenant_id = ? AND id IN ({$placeholders})"
        );
        $stmt->execute([$tenantId, ...array_values($groupIds)]);
        return array_column($stmt->fetchAll(), 'id');
    }

    public function createGroup(string $tenantId, string $name, int $minSelect, int $maxSelect, bool $isRequired): ModifierGroup
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO modifier_groups (tenant_id, name, min_select, max_select, is_required)
             VALUES (:tenant_id, :name, :min_select, :max_select, :is_required)
             RETURNING *'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'name' => $name,
            'min_select' => $minSelect,
            'max_select' => $maxSelect,
            'is_required' => Row::pgBool($isRequired),
        ]);
        return ModifierGroup::fromRow($stmt->fetch());
    }

    public function updateGroup(string $groupId, string $name, int $minSelect, int $maxSelect, bool $isRequired): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE modifier_groups
                SET name = :name, min_select = :min_select, max_select = :max_select, is_required = :is_required
              WHERE id = :id'
        );
        $stmt->execute([
            'id' => $groupId,
            'name' => $name,
            'min_select' => $minSelect,
            'max_select' => $maxSelect,
            'is_required' => Row::pgBool($isRequired),
        ]);
    }

    public function deleteGroup(string $groupId): void
    {
        $this->pdo->prepare('DELETE FROM modifier_groups WHERE id = :id')->execute(['id' => $groupId]);
    }

    // ---------- Opciones ----------

    /** Con el tenant en la consulta: la opcion cuelga del grupo, que es de la empresa. */
    public function getModifier(string $tenantId, string $modifierId): ?Modifier
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM modifiers m
               JOIN modifier_groups g ON g.id = m.group_id
              WHERE m.id = :id AND g.tenant_id = :tenant_id'
        );
        $stmt->execute(['id' => $modifierId, 'tenant_id' => $tenantId]);
        $row = $stmt->fetch();
        return $row === false ? null : Modifier::fromRow($row);
    }

    public function createModifier(string $groupId, string $name, int $priceDeltaCents, bool $isAvailable): Modifier
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO modifiers (group_id, name, price_delta, is_available)
             VALUES (:group_id, :name, :price_delta, :is_available)
             RETURNING *'
        );
        $stmt->execute([
            'group_id' => $groupId,
            'name' => $name,
            'price_delta' => Money::toDecimalString($priceDeltaCents),
            'is_available' => Row::pgBool($isAvailable),
        ]);
        return Modifier::fromRow($stmt->fetch());
    }

    public function updateModifier(string $modifierId, string $name, int $priceDeltaCents, bool $isAvailable): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE modifiers SET name = :name, price_delta = :price_delta, is_available = :is_available WHERE id = :id'
        );
        $stmt->execute([
            'id' => $modifierId,
            'name' => $name,
            'price_delta' => Money::toDecimalString($priceDeltaCents),
            'is_available' => Row::pgBool($isAvailable),
        ]);
    }

    public function deleteModifier(string $modifierId): void
    {
        $this->pdo->prepare('DELETE FROM modifiers WHERE id = :id')->execute(['id' => $modifierId]);
    }

    /**
     * Si alguna opcion del grupo ya se vendio.
     *
     * `order_item_modifiers.modifier_id` es una clave foranea sin ON DELETE,
     * asi que Postgres bloquearia el borrado de todos modos; esto es para
     * poder decir por que en vez de devolver un error de base de datos.
     */
    public function groupWasSold(string $groupId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM order_item_modifiers oim
               JOIN modifiers m ON m.id = oim.modifier_id
              WHERE m.group_id = :group_id
              LIMIT 1'
        );
        $stmt->execute(['group_id' => $groupId]);
        return $stmt->fetchColumn() !== false;
    }

    public function modifierWasSold(string $modifierId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM order_item_modifiers WHERE modifier_id = :id LIMIT 1');
        $stmt->execute(['id' => $modifierId]);
        return $stmt->fetchColumn() !== false;
    }

    // ---------- Asignacion producto <-> grupo ----------

    /**
     * Los grupos de cada producto del tenant, indexados por item_id.
     *
     * @return array<string, string[]>
     */
    public function groupIdsByItem(string $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT img.item_id, img.group_id
               FROM item_modifier_groups img
               JOIN menu_items i ON i.id = img.item_id
               JOIN menu_categories c ON c.id = i.category_id
              WHERE c.tenant_id = :tenant_id
           ORDER BY img.item_id, img.sort_order'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['item_id']][] = $row['group_id'];
        }
        return $result;
    }

    /**
     * Reemplaza los grupos de un producto por la lista dada, en ese orden.
     *
     * El `sort_order` es la posicion en la lista: es como se van a mostrar al
     * tomar el pedido, y ahi el orden importa (primero el termino de la
     * carne, despues las adiciones).
     *
     * @param string[] $groupIds
     */
    public function setItemGroups(string $itemId, array $groupIds): void
    {
        $this->pdo->prepare('DELETE FROM item_modifier_groups WHERE item_id = :item_id')
            ->execute(['item_id' => $itemId]);

        if ($groupIds === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO item_modifier_groups (item_id, group_id, sort_order) VALUES (:item_id, :group_id, :sort_order)'
        );
        foreach (array_values($groupIds) as $posicion => $groupId) {
            $stmt->execute(['item_id' => $itemId, 'group_id' => $groupId, 'sort_order' => $posicion]);
        }
    }
}
