<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Money;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderStatusRow;
use PDO;

final class OrderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Numeracion segura ante concurrencia: delega en la funcion de Postgres
     * next_order_number, que incrementa branches.order_seq atomicamente.
     */
    public function nextOrderNumber(string $branchId): string
    {
        $stmt = $this->pdo->prepare('SELECT next_order_number(:branch_id)');
        $stmt->execute(['branch_id' => $branchId]);
        return (string) $stmt->fetchColumn();
    }

    public function getByIdempotencyKey(string $tenantId, string $key): ?Order
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM orders WHERE tenant_id = :tenant_id AND idempotency_key = :key'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'key' => $key]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->getById($tenantId, (string) $id);
    }

    public function getById(string $tenantId, string $orderId): ?Order
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : Order::fromRow($row, $this->itemsForOrders([$row['id']])[$row['id']] ?? []);
    }

    /**
     * Igual que getById pero bloqueando la fila hasta el fin de la
     * transaccion.
     *
     * La version Python no bloquea nada y por eso su propio CLAUDE.md
     * anota que "dos cajeros que avancen el estado a la vez podrian
     * pisarse": ambos leen el mismo estado de origen, ambos validan contra
     * el, y el segundo pisa al primero. Con FOR UPDATE el segundo espera y
     * relee, asi que valida la transicion contra el estado que quedo de
     * verdad.
     */
    public function getByIdForUpdate(string $tenantId, string $orderId): ?Order
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM orders WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : Order::fromRow($row, $this->itemsForOrders([$row['id']])[$row['id']] ?? []);
    }

    /** @return Order[] */
    public function listForBranch(string $tenantId, string $branchId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM orders
             WHERE tenant_id = :tenant_id AND branch_id = :branch_id
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('tenant_id', $tenantId);
        $stmt->bindValue('branch_id', $branchId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $itemsByOrder = $this->itemsForOrders(array_column($rows, 'id'));
        return array_map(
            static fn (array $row) => Order::fromRow($row, $itemsByOrder[$row['id']] ?? []),
            $rows,
        );
    }

    /**
     * Filtra por order_statuses.category, nunca por code: cada restaurante
     * nombra sus estados distinto pero la categoria es la parte normalizada.
     *
     * @param string[] $categories
     * @return Order[]
     */
    public function listByStatusCategories(string $tenantId, string $branchId, array $categories): array
    {
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT o.*, t.code AS table_code,
                    s.id AS s_id, s.code AS s_code, s.name AS s_name, s.category AS s_category,
                    s.color AS s_color, s.sort_order AS s_sort_order,
                    s.is_initial AS s_is_initial, s.is_final AS s_is_final
             FROM orders o
             JOIN order_statuses s ON s.id = o.status_id
             LEFT JOIN tables t ON t.id = o.table_id
             WHERE o.tenant_id = ? AND o.branch_id = ? AND s.category IN ({$placeholders})
             ORDER BY o.created_at"
        );
        $stmt->execute([$tenantId, $branchId, ...array_values($categories)]);

        $rows = $stmt->fetchAll();
        $itemsByOrder = $this->itemsForOrders(array_column($rows, 'id'));

        return array_map(
            static fn (array $row) => Order::fromRow(
                $row,
                $itemsByOrder[$row['id']] ?? [],
                OrderStatusRow::fromRow([
                    'id' => $row['s_id'],
                    'code' => $row['s_code'],
                    'name' => $row['s_name'],
                    'category' => $row['s_category'],
                    'color' => $row['s_color'],
                    'sort_order' => $row['s_sort_order'],
                    'is_initial' => $row['s_is_initial'],
                    'is_final' => $row['s_is_final'],
                ]),
                $row['table_code'],
            ),
            $rows,
        );
    }

    /**
     * @param string[] $orderIds
     * @return array<string, OrderItem[]>
     */
    private function itemsForOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM order_items WHERE order_id IN ({$placeholders})");
        $stmt->execute(array_values($orderIds));
        $itemRows = $stmt->fetchAll();
        if ($itemRows === []) {
            return [];
        }

        $modifiersByItem = $this->modifiersForItems(array_column($itemRows, 'id'));

        $result = [];
        foreach ($itemRows as $row) {
            $result[$row['order_id']][] = OrderItem::fromRow($row, $modifiersByItem[$row['id']] ?? []);
        }
        return $result;
    }

    /**
     * @param string[] $itemIds
     * @return array<string, OrderItemModifier[]>
     */
    private function modifiersForItems(array $itemIds): array
    {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM order_item_modifiers WHERE order_item_id IN ({$placeholders})");
        $stmt->execute(array_values($itemIds));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['order_item_id']][] = OrderItemModifier::fromRow($row);
        }
        return $result;
    }

    public function create(
        string $tenantId,
        string $branchId,
        string $statusId,
        string $orderNumber,
        string $channel,
        ?string $customerId,
        ?string $tableId,
        ?string $createdBy,
        ?string $idempotencyKey,
        ?string $notes,
        int $subtotalCents,
        int $taxTotalCents,
        int $deliveryFeeCents,
        int $discountCents,
        int $tipCents,
        int $totalCents,
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (
                tenant_id, branch_id, status_id, order_number, channel, customer_id, table_id,
                created_by, idempotency_key, notes, subtotal, tax_total, delivery_fee, discount, tip, total
             ) VALUES (
                :tenant_id, :branch_id, :status_id, :order_number, :channel, :customer_id, :table_id,
                :created_by, :idempotency_key, :notes, :subtotal, :tax_total, :delivery_fee, :discount, :tip, :total
             ) RETURNING id'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'status_id' => $statusId,
            'order_number' => $orderNumber,
            'channel' => $channel,
            'customer_id' => $customerId,
            'table_id' => $tableId,
            'created_by' => $createdBy,
            'idempotency_key' => $idempotencyKey,
            'notes' => $notes,
            'subtotal' => Money::toDecimalString($subtotalCents),
            'tax_total' => Money::toDecimalString($taxTotalCents),
            'delivery_fee' => Money::toDecimalString($deliveryFeeCents),
            'discount' => Money::toDecimalString($discountCents),
            'tip' => Money::toDecimalString($tipCents),
            'total' => Money::toDecimalString($totalCents),
        ]);
        return (string) $stmt->fetchColumn();
    }

    /** @param string $taxRate fraccion decimal, p.ej. "0.0800" (NUMERIC(5,4)) */
    public function addItem(
        string $orderId,
        string $menuItemId,
        string $nameSnapshot,
        int $quantity,
        int $unitPriceCents,
        string $taxRate,
        int $taxAmountCents,
        int $lineTotalCents,
        ?string $notes,
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_items (
                order_id, menu_item_id, name_snapshot, quantity, unit_price, tax_rate, tax_amount, line_total, notes
             ) VALUES (
                :order_id, :menu_item_id, :name_snapshot, :quantity, :unit_price, :tax_rate, :tax_amount, :line_total, :notes
             ) RETURNING id'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'menu_item_id' => $menuItemId,
            'name_snapshot' => $nameSnapshot,
            'quantity' => $quantity,
            'unit_price' => Money::toDecimalString($unitPriceCents),
            'tax_rate' => $taxRate,
            'tax_amount' => Money::toDecimalString($taxAmountCents),
            'line_total' => Money::toDecimalString($lineTotalCents),
            'notes' => $notes,
        ]);
        return (string) $stmt->fetchColumn();
    }

    public function addItemModifier(
        string $orderItemId,
        string $modifierId,
        string $nameSnapshot,
        int $priceDeltaCents,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_item_modifiers (order_item_id, modifier_id, name_snapshot, price_delta)
             VALUES (:order_item_id, :modifier_id, :name_snapshot, :price_delta)'
        );
        $stmt->execute([
            'order_item_id' => $orderItemId,
            'modifier_id' => $modifierId,
            'name_snapshot' => $nameSnapshot,
            'price_delta' => Money::toDecimalString($priceDeltaCents),
        ]);
    }

    public function addStatusHistory(string $orderId, string $statusId, ?string $changedBy, ?string $note): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_status_history (order_id, status_id, changed_by, note)
             VALUES (:order_id, :status_id, :changed_by, :note)'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'status_id' => $statusId,
            'changed_by' => $changedBy,
            'note' => $note,
        ]);
    }

    public function setStatus(string $orderId, string $statusId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE orders SET status_id = :status_id, updated_at = now() WHERE id = :id'
        );
        $stmt->execute(['status_id' => $statusId, 'id' => $orderId]);
    }
}
