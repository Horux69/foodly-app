<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Money;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemComponent;
use App\Models\OrderItemModifier;
use App\Models\OrderStatusEvent;
use App\Models\OrderStatusRow;
use App\Services\OrderCursor;
use App\Services\OrderListFilters;
use PDO;

final class OrderRepository
{
    /**
     * Un pedido nunca se lee suelto: el estado se necesita en todas partes
     * (categoria para el KDS y los filtros, nombre y color para pintarlo) y
     * la mesa es un codigo que vive en otra tabla. Una sola consulta base
     * para no repetir el join —ni olvidarlo en una lectura y que la interfaz
     * reciba un pedido sin estado.
     */
    private const SELECT_ORDER =
        'SELECT o.*, t.code AS table_code,
                s.id AS s_id, s.code AS s_code, s.name AS s_name, s.category AS s_category,
                s.color AS s_color, s.sort_order AS s_sort_order,
                s.is_initial AS s_is_initial, s.is_final AS s_is_final
         FROM orders o
         JOIN order_statuses s ON s.id = o.status_id
         LEFT JOIN tables t ON t.id = o.table_id';

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
        $stmt = $this->pdo->prepare(self::SELECT_ORDER . ' WHERE o.tenant_id = :tenant_id AND o.id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrateAll([$row])[0];
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
        // FOR UPDATE OF o y no FOR UPDATE a secas: se quiere bloquear el
        // pedido, no las filas de order_statuses ni de tables que trae el
        // join —bloquear un estado seria serializar a todo el restaurante.
        $stmt = $this->pdo->prepare(
            self::SELECT_ORDER . ' WHERE o.tenant_id = :tenant_id AND o.id = :id FOR UPDATE OF o'
        );
        $stmt->execute(['tenant_id' => $tenantId, 'id' => $orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->hydrateAll([$row])[0];
    }

    /**
     * Una pagina de pedidos de la sucursal, de la mas reciente hacia atras.
     *
     * Devuelve hasta $filters->limit + 1: la de mas no se muestra, solo dice
     * que hay pagina siguiente sin tener que contar el total.
     *
     * @return Order[]
     */
    public function listForBranch(string $tenantId, string $branchId, OrderListFilters $filters): array
    {
        $where = ['o.tenant_id = :tenant_id', 'o.branch_id = :branch_id'];
        $params = ['tenant_id' => $tenantId, 'branch_id' => $branchId];

        if ($filters->statusCategory !== null) {
            $where[] = 's.category = :status_category';
            $params['status_category'] = $filters->statusCategory;
        }
        if ($filters->channel !== null) {
            $where[] = 'o.channel = :channel';
            $params['channel'] = $filters->channel;
        }
        if ($filters->search !== null) {
            // Numero de pedido o telefono: las dos cosas que alguien tiene a
            // mano cuando pregunta por un pedido en el mostrador.
            $where[] = "(o.order_number ILIKE :q ESCAPE '\\' OR c.phone ILIKE :q ESCAPE '\\')";
            $params['q'] = '%' . addcslashes($filters->search, '\\%_') . '%';
        }
        // El dia se mide en la zona de la sucursal, no en la del servidor: a
        // las 8 de la noche en Bogota ya es manana en UTC, y "los pedidos de
        // hoy" empezarian a mostrarse cortados.
        if ($filters->fromDate !== null) {
            $where[] = '(o.created_at AT TIME ZONE b.timezone)::date >= :from_date::date';
            $params['from_date'] = $filters->fromDate;
        }
        if ($filters->toDate !== null) {
            $where[] = '(o.created_at AT TIME ZONE b.timezone)::date <= :to_date::date';
            $params['to_date'] = $filters->toDate;
        }
        if ($filters->cursor !== null) {
            [$cursorAt, $cursorId] = OrderCursor::decode($filters->cursor);
            $where[] = '(o.created_at, o.id) < (:cursor_at::timestamptz, :cursor_id::uuid)';
            $params['cursor_at'] = $cursorAt;
            $params['cursor_id'] = $cursorId;
        }

        $stmt = $this->pdo->prepare(
            self::SELECT_ORDER
            . ' JOIN branches b ON b.id = o.branch_id'
            . ' LEFT JOIN customers c ON c.id = o.customer_id'
            // INNER JOIN cuando se piden solo domicilios: tener entrega es
            // exactamente lo que hace domicilio a un pedido.
            . ($filters->onlyDelivery ? ' JOIN delivery_info di ON di.order_id = o.id' : '')
            . ' WHERE ' . implode(' AND ', $where)
            // El id desempata para que el cursor no se salte un pedido
            // cuando dos comparten el instante de creacion.
            . ' ORDER BY o.created_at DESC, o.id DESC'
            . ' LIMIT :limit'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $filters->limit + 1, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateAll($stmt->fetchAll());
    }

    /**
     * Filtra por order_statuses.category, nunca por code: cada restaurante
     * nombra sus estados distinto pero la categoria es la parte normalizada.
     *
     * @param string[] $categories
     * @return Order[]
     */
    public function listByStatusCategories(
        string $tenantId,
        string $branchId,
        array $categories,
        ?int $sinceMinutes = null,
    ): array {
        $placeholders = implode(',', array_fill(0, count($categories), '?'));

        // Con ventana de tiempo para lo ya despachado: los completados del
        // dia se acumulan y el tablero solo quiere los de hace un rato. Mira
        // updated_at, que es cuando cambio de estado, no cuando se creo.
        $reciente = $sinceMinutes !== null
            ? ' AND o.updated_at > now() - make_interval(mins => ?)'
            : '';

        $stmt = $this->pdo->prepare(
            self::SELECT_ORDER
            . " WHERE o.tenant_id = ? AND o.branch_id = ? AND s.category IN ({$placeholders})"
            . $reciente
            . ' ORDER BY o.created_at'
        );

        $params = [$tenantId, $branchId, ...array_values($categories)];
        if ($sinceMinutes !== null) {
            $params[] = $sinceMinutes;
        }
        $stmt->execute($params);

        return $this->hydrateAll($stmt->fetchAll());
    }

    /**
     * Filas de SELECT_ORDER a pedidos, con sus lineas en una sola consulta
     * mas (y los modificadores en otra): tres consultas para la lista
     * entera, no tres por pedido.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return Order[]
     */
    private function hydrateAll(array $rows): array
    {
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

        $itemIds = array_column($itemRows, 'id');
        $modifiersByItem = $this->modifiersForItems($itemIds);
        $componentsByItem = $this->componentsForItems($itemIds);

        $result = [];
        foreach ($itemRows as $row) {
            $result[$row['order_id']][] = OrderItem::fromRow(
                $row,
                $modifiersByItem[$row['id']] ?? [],
                $componentsByItem[$row['id']] ?? [],
            );
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

    /**
     * Lo que llevaba cada combo vendido, en el orden en que se guardo.
     *
     * @param string[] $itemIds
     * @return array<string, OrderItemComponent[]>
     */
    private function componentsForItems(array $itemIds): array
    {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM order_item_components WHERE order_item_id IN ({$placeholders}) ORDER BY sort_order"
        );
        $stmt->execute(array_values($itemIds));

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['order_item_id']][] = OrderItemComponent::fromRow($row);
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
        ?string $discountReasonId = null,
        ?string $discountBy = null,
    ): string {
        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (
                tenant_id, branch_id, status_id, order_number, channel, customer_id, table_id,
                created_by, idempotency_key, notes, subtotal, tax_total, delivery_fee, discount, tip, total,
                discount_reason_id, discount_by
             ) VALUES (
                :tenant_id, :branch_id, :status_id, :order_number, :channel, :customer_id, :table_id,
                :created_by, :idempotency_key, :notes, :subtotal, :tax_total, :delivery_fee, :discount, :tip, :total,
                :discount_reason_id, :discount_by
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
            'discount_reason_id' => $discountReasonId,
            'discount_by' => $discountBy,
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

    /**
     * Congela lo que lleva un combo en la linea recien creada.
     *
     * @param array<int, array{item_id: string, name: string, quantity: int}> $componentes
     *        ya expandidos por la cantidad pedida
     */
    public function addItemComponents(string $orderItemId, array $componentes): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_item_components (order_item_id, menu_item_id, name_snapshot, quantity, sort_order)
             VALUES (:order_item_id, :menu_item_id, :name_snapshot, :quantity, :sort_order)'
        );
        foreach (array_values($componentes) as $posicion => $componente) {
            $stmt->execute([
                'order_item_id' => $orderItemId,
                'menu_item_id' => $componente['item_id'],
                'name_snapshot' => $componente['name'],
                'quantity' => $componente['quantity'],
                'sort_order' => $posicion,
            ]);
        }
    }

    /**
     * Quita una linea del pedido.
     *
     * Sus modificadores y sus componentes se van con ella: las dos tablas
     * cuelgan de order_items con ON DELETE CASCADE.
     */
    public function removeItem(string $orderItemId): void
    {
        $this->pdo->prepare('DELETE FROM order_items WHERE id = :id')->execute(['id' => $orderItemId]);
    }

    /**
     * Cambia la cantidad de una linea ya congelada.
     *
     * El precio unitario no se toca —es el del momento de la venta— pero el
     * impuesto y el total de la linea sí, porque dependen de cuantas son.
     */
    public function setItemQuantity(
        string $orderItemId,
        int $quantity,
        int $taxAmountCents,
        int $lineTotalCents,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE order_items
                SET quantity = :quantity, tax_amount = :tax_amount, line_total = :line_total
              WHERE id = :id'
        );
        $stmt->execute([
            'quantity' => $quantity,
            'tax_amount' => Money::toDecimalString($taxAmountCents),
            'line_total' => Money::toDecimalString($lineTotalCents),
            'id' => $orderItemId,
        ]);
    }

    /**
     * Reescala lo que lleva un combo cuando cambia la cantidad de la linea.
     *
     * Los componentes se congelan ya multiplicados —dos combos son dos
     * hamburguesas—, asi que al pasar de dos a tres hay que volver a
     * multiplicar. Se hace sobre lo guardado y no releyendo el menu: la
     * composicion de la venta no cambia porque el combo haya cambiado hoy.
     */
    public function rescaleItemComponents(string $orderItemId, int $anterior, int $nueva): void
    {
        if ($anterior === $nueva || $anterior < 1) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE order_item_components
                SET quantity = GREATEST(1, (quantity / :anterior) * :nueva)
              WHERE order_item_id = :id'
        );
        $stmt->execute(['anterior' => $anterior, 'nueva' => $nueva, 'id' => $orderItemId]);
    }

    /**
     * Fija el descuento del pedido, con su motivo y quien lo autorizo.
     *
     * Los totales se guardan aparte, con updateTotals: aqui solo va el
     * ajuste, para que la aritmetica siga saliendo del dominio.
     */
    public function setDiscount(string $orderId, int $discountCents, ?string $reasonId, ?string $by): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE orders
                SET discount = :discount, discount_reason_id = :reason, discount_by = :by, updated_at = now()
              WHERE id = :id'
        );
        $stmt->execute([
            'discount' => Money::toDecimalString($discountCents),
            'reason' => $reasonId,
            'by' => $by,
            'id' => $orderId,
        ]);
    }

    /** Los totales del pedido despues de una edicion. Los ajustes no se tocan aqui. */
    public function updateTotals(string $orderId, int $subtotalCents, int $taxTotalCents, int $totalCents): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE orders
                SET subtotal = :subtotal, tax_total = :tax_total, total = :total, updated_at = now()
              WHERE id = :id'
        );
        $stmt->execute([
            'subtotal' => Money::toDecimalString($subtotalCents),
            'tax_total' => Money::toDecimalString($taxTotalCents),
            'total' => Money::toDecimalString($totalCents),
            'id' => $orderId,
        ]);
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

    /**
     * La bitacora del pedido, del primer estado al ultimo.
     *
     * Se escribia desde siempre y solo la leia el reporte de tiempos: nadie
     * podia ver quien movio que. El nombre del usuario se resuelve con LEFT
     * JOIN porque changed_by es ON DELETE SET NULL —un empleado que ya no
     * esta no debe borrar la historia del pedido.
     *
     * @return OrderStatusEvent[]
     */
    public function statusHistory(string $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.id, h.note, h.changed_at, u.name AS changed_by_name,
                    s.id AS s_id, s.code AS s_code, s.name AS s_name, s.category AS s_category,
                    s.color AS s_color, s.sort_order AS s_sort_order,
                    s.is_initial AS s_is_initial, s.is_final AS s_is_final
               FROM order_status_history h
               JOIN order_statuses s ON s.id = h.status_id
               LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.order_id = :order_id
              ORDER BY h.changed_at, h.id'
        );
        $stmt->execute(['order_id' => $orderId]);
        return array_map(OrderStatusEvent::fromRow(...), $stmt->fetchAll());
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
