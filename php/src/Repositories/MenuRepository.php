<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Money;
use App\Core\Row;
use App\Models\BranchMenuOverride;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use PDO;

final class MenuRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Menu operativo completo: categorias activas con sus productos y los
     * grupos de modificadores de cada uno.
     *
     * Tres consultas y el armado en PHP, no una por producto: es el
     * equivalente del joinedload que usa SQLAlchemy en Python.
     *
     * @return MenuCategory[]
     */
    public function listCategoriesWithItems(string $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM menu_categories
             WHERE tenant_id = :tenant_id AND is_active = true
             ORDER BY sort_order'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $categoryRows = $stmt->fetchAll();
        if ($categoryRows === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT i.* FROM menu_items i
             JOIN menu_categories c ON c.id = i.category_id
             WHERE c.tenant_id = :tenant_id
             ORDER BY i.sort_order'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        $itemRows = $stmt->fetchAll();

        $groupsByItem = $this->modifierGroupsByItem(array_column($itemRows, 'id'));

        $itemsByCategory = [];
        foreach ($itemRows as $row) {
            $itemsByCategory[$row['category_id']][] = MenuItem::fromRow($row, $groupsByItem[$row['id']] ?? []);
        }

        return array_map(
            static fn (array $row) => MenuCategory::fromRow($row, $itemsByCategory[$row['id']] ?? []),
            $categoryRows,
        );
    }

    /**
     * @param string[] $itemIds
     * @return array<string, ModifierGroup[]> grupos (con sus modificadores) indexados por item_id
     */
    private function modifierGroupsByItem(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT img.item_id, g.*
             FROM item_modifier_groups img
             JOIN modifier_groups g ON g.id = img.group_id
             WHERE img.item_id IN ({$placeholders})
             ORDER BY img.sort_order"
        );
        $stmt->execute(array_values($itemIds));
        $groupRows = $stmt->fetchAll();
        if ($groupRows === []) {
            return [];
        }

        $modifiersByGroup = $this->modifiersByGroup(array_unique(array_column($groupRows, 'id')));

        $result = [];
        foreach ($groupRows as $row) {
            $result[$row['item_id']][] = ModifierGroup::fromRow($row, $modifiersByGroup[$row['id']] ?? []);
        }
        return $result;
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
        $stmt = $this->pdo->prepare("SELECT * FROM modifiers WHERE group_id IN ({$placeholders})");
        $stmt->execute(array_values($groupIds));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['group_id']][] = Modifier::fromRow($row);
        }
        return $result;
    }

    /** @return array<string, BranchMenuOverride> indexados por menu_item_id */
    public function getOverridesForBranch(string $branchId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM branch_menu_overrides WHERE branch_id = :branch_id');
        $stmt->execute(['branch_id' => $branchId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['menu_item_id']] = BranchMenuOverride::fromRow($row);
        }
        return $result;
    }

    /**
     * Incluye las inactivas: la pantalla de administracion tiene que verlas
     * para poder reactivarlas.
     *
     * @return MenuCategory[]
     */
    public function listAllCategories(string $tenantId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM menu_categories WHERE tenant_id = :tenant_id ORDER BY sort_order');
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map(static fn (array $row) => MenuCategory::fromRow($row), $stmt->fetchAll());
    }

    /**
     * Catalogo crudo para administracion: precio base sin overrides y con los
     * archivados incluidos. La vista operativa usa listCategoriesWithItems.
     *
     * @return MenuItem[]
     */
    public function listAllItems(string $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.* FROM menu_items i
             JOIN menu_categories c ON c.id = i.category_id
             WHERE c.tenant_id = :tenant_id
             ORDER BY c.sort_order, i.sort_order'
        );
        $stmt->execute(['tenant_id' => $tenantId]);
        return array_map(static fn (array $row) => MenuItem::fromRow($row), $stmt->fetchAll());
    }

    public function getCategory(string $tenantId, string $categoryId): ?MenuCategory
    {
        $stmt = $this->pdo->prepare('SELECT * FROM menu_categories WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $categoryId]);
        $row = $stmt->fetch();
        return $row === false ? null : MenuCategory::fromRow($row);
    }

    public function createCategory(string $tenantId, string $name, int $sortOrder): MenuCategory
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO menu_categories (tenant_id, name, sort_order)
             VALUES (:tenant_id, :name, :sort_order)
             RETURNING *'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'name' => $name, 'sort_order' => $sortOrder]);
        return MenuCategory::fromRow($stmt->fetch());
    }

    public function getItem(string $tenantId, string $itemId): ?MenuItem
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.* FROM menu_items i
             JOIN menu_categories c ON c.id = i.category_id
             WHERE c.tenant_id = :tenant_id AND i.id = :id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $itemId]);
        $row = $stmt->fetch();
        return $row === false ? null : MenuItem::fromRow($row);
    }

    /** Producto con su impuesto resuelto y sus modificadores: lo que necesita la toma de pedidos. */
    public function getItemWithModifiers(string $tenantId, string $itemId): ?MenuItem
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, t.rate AS tax_rate, t.included_in_price AS tax_included_in_price
             FROM menu_items i
             JOIN menu_categories c ON c.id = i.category_id
             LEFT JOIN tax_rates t ON t.id = i.tax_rate_id
             WHERE c.tenant_id = :tenant_id AND i.id = :id'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $itemId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return MenuItem::fromRow($row, $this->modifierGroupsByItem([$row['id']])[$row['id']] ?? []);
    }

    public function createItem(
        string $categoryId,
        string $name,
        int $basePriceCents,
        ?string $taxRateId,
        ?string $description,
        ?int $prepMinutes,
        int $sortOrder = 0,
    ): MenuItem {
        $stmt = $this->pdo->prepare(
            'INSERT INTO menu_items (category_id, name, base_price, tax_rate_id, description, prep_minutes, sort_order)
             VALUES (:category_id, :name, :base_price, :tax_rate_id, :description, :prep_minutes, :sort_order)
             RETURNING *'
        );
        $stmt->execute([
            'category_id' => $categoryId,
            'name' => $name,
            'base_price' => Money::toDecimalString($basePriceCents),
            'tax_rate_id' => $taxRateId,
            'description' => $description,
            'prep_minutes' => $prepMinutes,
            'sort_order' => $sortOrder,
        ]);
        return MenuItem::fromRow($stmt->fetch());
    }

    // ---------- Combos ----------

    /**
     * Que lleva cada combo del tenant, indexado por el id del combo.
     *
     * Una consulta para todos y no una por producto: la pantalla del menu
     * necesita saber cuales son combos para pintarlos distinto.
     *
     * @return array<string, array<int, array{item_id: string, name: string, quantity: int}>>
     */
    public function componentsByItem(string $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.parent_item_id, c.component_item_id, c.quantity, hijo.name
               FROM menu_item_components c
               JOIN menu_items padre ON padre.id = c.parent_item_id
               JOIN menu_categories cat ON cat.id = padre.category_id
               JOIN menu_items hijo ON hijo.id = c.component_item_id
              WHERE cat.tenant_id = :tenant_id
           ORDER BY c.parent_item_id, c.sort_order'
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['parent_item_id']][] = [
                'item_id' => $row['component_item_id'],
                'name' => $row['name'],
                'quantity' => (int) $row['quantity'],
            ];
        }
        return $result;
    }

    /**
     * Lo que lleva un combo concreto, en orden.
     *
     * @return array<int, array{item_id: string, name: string, quantity: int}>
     */
    public function componentsOf(string $itemId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.component_item_id, c.quantity, hijo.name
               FROM menu_item_components c
               JOIN menu_items hijo ON hijo.id = c.component_item_id
              WHERE c.parent_item_id = :id
           ORDER BY c.sort_order'
        );
        $stmt->execute(['id' => $itemId]);

        return array_map(static fn (array $row) => [
            'item_id' => $row['component_item_id'],
            'name' => $row['name'],
            'quantity' => (int) $row['quantity'],
        ], $stmt->fetchAll());
    }

    /**
     * Reemplaza lo que lleva un combo.
     *
     * @param array<int, array{item_id: string, quantity: int}> $componentes
     */
    public function setComponents(string $itemId, array $componentes): void
    {
        $this->pdo->prepare('DELETE FROM menu_item_components WHERE parent_item_id = :id')
            ->execute(['id' => $itemId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO menu_item_components (parent_item_id, component_item_id, quantity, sort_order)
             VALUES (:parent, :component, :quantity, :sort_order)'
        );
        foreach (array_values($componentes) as $posicion => $componente) {
            $stmt->execute([
                'parent' => $itemId,
                'component' => $componente['item_id'],
                'quantity' => $componente['quantity'],
                'sort_order' => $posicion,
            ]);
        }
    }

    /** Los combos que llevan este producto: lo que impide archivarlo sin darse cuenta. */
    public function combosThatUse(string $itemId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT padre.name
               FROM menu_item_components c
               JOIN menu_items padre ON padre.id = c.parent_item_id
              WHERE c.component_item_id = :id AND NOT padre.is_archived
           ORDER BY padre.name'
        );
        $stmt->execute(['id' => $itemId]);
        return array_column($stmt->fetchAll(), 'name');
    }

    /**
     * Actualiza solo las columnas presentes en $changes.
     *
     * Las claves se validan contra una lista blanca antes de armar el SET:
     * nunca se interpola en el SQL algo que venga del cliente.
     *
     * @param array<string, mixed> $changes columnas de menu_items, ya normalizadas
     */
    public function updateItem(string $itemId, array $changes): void
    {
        $allowed = [
            'category_id', 'name', 'base_price', 'tax_rate_id',
            'description', 'prep_minutes', 'sort_order', 'is_archived',
        ];
        $columns = array_intersect(array_keys($changes), $allowed);
        if ($columns === []) {
            return;
        }

        $assignments = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", $columns));
        $stmt = $this->pdo->prepare("UPDATE menu_items SET {$assignments} WHERE id = :id");

        $params = ['id' => $itemId];
        foreach ($columns as $column) {
            $value = $changes[$column];
            $params[$column] = is_bool($value) ? Row::pgBool($value) : $value;
        }
        $stmt->execute($params);
    }

    public function setItemAvailability(string $itemId, bool $isAvailable): void
    {
        $stmt = $this->pdo->prepare('UPDATE menu_items SET is_available = :is_available WHERE id = :id');
        $stmt->execute(['is_available' => Row::pgBool($isAvailable), 'id' => $itemId]);
    }

    public function getBranchOverride(string $branchId, string $menuItemId): ?BranchMenuOverride
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM branch_menu_overrides WHERE branch_id = :branch_id AND menu_item_id = :menu_item_id'
        );
        $stmt->execute(['branch_id' => $branchId, 'menu_item_id' => $menuItemId]);
        $row = $stmt->fetch();
        return $row === false ? null : BranchMenuOverride::fromRow($row);
    }

    /** Un override por (sucursal, producto): el UNIQUE del esquema lo garantiza y el UPSERT lo aprovecha. */
    public function upsertBranchOverride(
        string $branchId,
        string $menuItemId,
        ?int $priceCents,
        ?bool $isAvailable,
    ): BranchMenuOverride {
        $stmt = $this->pdo->prepare(
            'INSERT INTO branch_menu_overrides (branch_id, menu_item_id, price, is_available)
             VALUES (:branch_id, :menu_item_id, :price, :is_available)
             ON CONFLICT (branch_id, menu_item_id)
             DO UPDATE SET price = EXCLUDED.price, is_available = EXCLUDED.is_available
             RETURNING *'
        );
        $stmt->execute([
            'branch_id' => $branchId,
            'menu_item_id' => $menuItemId,
            'price' => $priceCents === null ? null : Money::toDecimalString($priceCents),
            'is_available' => $isAvailable === null ? null : Row::pgBool($isAvailable),
        ]);
        return BranchMenuOverride::fromRow($stmt->fetch());
    }
}
