<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Core\Database;
use App\Core\Money;
use App\Domain\PaymentBalance;
use App\Models\Order;
use App\Models\OrderStatusRow;
use App\Services\DeliveryInput;
use App\Services\OrderError;
use App\Services\OrderLineInput;
use App\Services\OrderListFilters;
use App\Services\OrderService;
use App\Services\OrderStatusError;
use App\Repositories\DeliveryRepository;
use App\Services\OrderStatusService;
use App\Services\PaymentService;

/** Equivalente PHP de app/api/v1/orders.py. */
final class OrderController
{
    public static function orderOut(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->orderNumber,
            'channel' => $order->channel,
            'created_at' => $order->createdAt,
            'table_code' => $order->tableCode,
            'status' => $order->status !== null ? self::statusOut($order->status) : null,
            'subtotal' => Money::toDecimalString($order->subtotalCents),
            'tax_total' => Money::toDecimalString($order->taxTotalCents),
            'delivery_fee' => Money::toDecimalString($order->deliveryFeeCents),
            'discount' => Money::toDecimalString($order->discountCents),
            'tip' => Money::toDecimalString($order->tipCents),
            'total' => Money::toDecimalString($order->totalCents),
            'notes' => $order->notes,
            'items' => array_map(static fn ($i) => [
                'id' => $i->id,
                'menu_item_id' => $i->menuItemId,
                'name_snapshot' => $i->nameSnapshot,
                'quantity' => $i->quantity,
                'unit_price' => Money::toDecimalString($i->unitPriceCents),
                'tax_amount' => Money::toDecimalString($i->taxAmountCents),
                'line_total' => Money::toDecimalString($i->lineTotalCents),
                'notes' => $i->notes,
                'modifiers' => array_map(static fn ($m) => [
                    'modifier_id' => $m->modifierId,
                    'name_snapshot' => $m->nameSnapshot,
                    'price_delta' => Money::toDecimalString($m->priceDeltaCents),
                ], $i->modifiers),
                // Lo que llevaba el combo cuando se vendio, no lo que lleva
                // hoy: sin precio, porque el paquete se cobra entero.
                'components' => array_map(static fn ($c) => [
                    'menu_item_id' => $c->menuItemId,
                    'name_snapshot' => $c->nameSnapshot,
                    'quantity' => $c->quantity,
                ], $i->components),
            ], $order->items),
        ];
    }

    public static function statusOut(OrderStatusRow $s): array
    {
        return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'category' => $s->category, 'color' => $s->color];
    }

    /**
     * @param array<string, mixed> $body
     * @return OrderLineInput[]
     */
    private static function linesFrom(array $body): array
    {
        $items = $body['items'] ?? null;
        if (!is_array($items) || $items === []) {
            throw new ApiException(422, "'items' debe traer al menos un producto");
        }

        return array_map(static function ($raw): OrderLineInput {
            if (!is_array($raw)) {
                throw new ApiException(422, 'Cada item debe ser un objeto');
            }
            $modifierIds = Request::stringList($raw, 'modifier_ids');
            foreach ($modifierIds as $id) {
                if (!Request::isUuid($id)) {
                    throw new ApiException(422, "'modifier_ids' solo acepta UUID validos");
                }
            }
            return new OrderLineInput(
                menuItemId: Request::uuid($raw, 'menu_item_id'),
                quantity: Request::int($raw, 'quantity', min: 1),
                modifierIds: $modifierIds,
                notes: Request::optionalString($raw, 'notes'),
            );
        }, $items);
    }

    /** Los tres ajustes de dinero del pedido, en centavos. @return array{0:int,1:int,2:int} */
    private static function adjustments(array $body): array
    {
        $read = static function (string $key) use ($body): int {
            if (!array_key_exists($key, $body) || $body[$key] === null) {
                return 0;
            }
            $cents = Money::fromDecimalString(Request::decimalString($body, $key));
            if ($cents < 0) {
                throw new ApiException(422, "'{$key}' no puede ser negativo");
            }
            return $cents;
        };
        return [$read('delivery_fee'), $read('discount'), $read('tip')];
    }

    /**
     * Datos de entrega, si el pedido es un domicilio. Su sola presencia es lo
     * que lo convierte en uno; no se deduce del canal, que el restaurante
     * puede haber bautizado como quiera.
     */
    private static function deliveryFrom(array $body): ?DeliveryInput
    {
        if (!array_key_exists('delivery', $body) || $body['delivery'] === null) {
            return null;
        }
        if (!is_array($body['delivery'])) {
            throw new ApiException(422, "'delivery' debe ser un objeto");
        }
        $raw = $body['delivery'];

        return new DeliveryInput(
            address: Request::string($raw, 'address', 1, 255),
            zoneId: Request::optionalUuid($raw, 'zone_id'),
            // Coordenadas como decimal en texto: la columna es NUMERIC(10,7) y
            // un float perderia justo los digitos que ubican el punto.
            lat: array_key_exists('lat', $raw) && $raw['lat'] !== null
                ? Request::decimalString($raw, 'lat')
                : null,
            lng: array_key_exists('lng', $raw) && $raw['lng'] !== null
                ? Request::decimalString($raw, 'lng')
                : null,
        );
    }

    public static function create(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.create');
        $branchId = Deps::activeBranchId($ctx);
        $body = Request::json();
        [$deliveryFee, $discount, $tip] = self::adjustments($body);

        try {
            $order = OrderService::createOrder(
                $ctx->tenantId,
                $branchId,
                $ctx->userId,
                Request::string($body, 'channel'),
                self::linesFrom($body),
                Request::optionalString($body, 'customer_phone'),
                Request::optionalString($body, 'customer_name'),
                Request::optionalString($body, 'table_code'),
                Request::optionalString($body, 'notes'),
                Request::optionalString($body, 'idempotency_key'),
                $deliveryFee,
                $discount,
                $tip,
                self::deliveryFrom($body),
            );
        } catch (OrderError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return new JsonResponse(self::orderOut($order), 201);
    }

    /**
     * Totales sin crear el pedido, para que la pantalla de venta los muestre
     * sin repetir la aritmetica del dominio.
     */
    public static function preview(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.create');
        $branchId = Deps::activeBranchId($ctx);
        $body = Request::json();
        [$deliveryFee, $discount, $tip] = self::adjustments($body);

        // La zona se acepta suelta y no dentro de 'delivery': previsualizar
        // no necesita la direccion, solo saber que tarifa se va a cobrar.
        $zoneId = Request::optionalUuid($body, 'zone_id');

        try {
            $preview = OrderService::previewTotals(
                $ctx->tenantId,
                $branchId,
                self::linesFrom($body),
                $deliveryFee,
                $discount,
                $tip,
                $zoneId,
            );
        } catch (OrderError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        $totals = $preview->totals;

        return [
            'subtotal' => Money::toDecimalString($totals->subtotalCents),
            'tax_total' => Money::toDecimalString($totals->taxTotalCents),
            'delivery_fee' => Money::toDecimalString($totals->deliveryFeeCents),
            'discount' => Money::toDecimalString($totals->discountCents),
            'tip' => Money::toDecimalString($totals->tipCents),
            'total' => Money::toDecimalString($totals->totalCents),
            // Ya redactado por Domain\DeliveryRules: la pantalla lo muestra,
            // no rehace la comparacion contra el subtotal.
            'delivery_warning' => $preview->minimumWarning,
        ];
    }

    /**
     * El pedido con todo lo que el panel de detalle necesita para pintarse.
     *
     * El saldo y la entrega viajan dentro y no en dos peticiones aparte, por
     * la misma razon que en la lista: abrir un pedido no deberia costar
     * cuatro llamadas. La aritmetica del saldo sigue siendo de
     * Domain\PaymentBalance.
     */
    public static function get(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');
        $order = OrderService::getOrder($ctx->tenantId, $params['order_id']);
        if ($order === null) {
            throw new ApiException(404, 'Pedido no encontrado');
        }

        $delivery = (new DeliveryRepository(Database::app()))->getForOrder($order->id);

        return self::orderOut($order) + [
            'balance' => self::balanceOut(PaymentService::getBalanceForOrder($order)),
            // Solo los domicilios tienen entrega; su presencia es lo que
            // convierte al pedido en uno.
            'delivery' => $delivery === null ? null : DeliveryController::infoOut($delivery),
        ];
    }

    /** Quien movio el pedido, cuando y con que nota. */
    public static function history(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');

        try {
            $events = OrderService::statusHistory($ctx->tenantId, $params['order_id']);
        } catch (OrderError $e) {
            throw new ApiException(404, $e->getMessage());
        }

        return array_map(static fn ($e) => [
            'id' => $e->id,
            'status' => self::statusOut($e->status),
            'changed_by_name' => $e->changedByName,
            'note' => $e->note,
            'changed_at' => $e->changedAt,
        ], $events);
    }

    public static function balanceOut(PaymentBalance $b): array
    {
        return [
            'total' => Money::toDecimalString($b->totalCents),
            'paid' => Money::toDecimalString($b->paidCents),
            'refunded' => Money::toDecimalString($b->refundedCents),
            'net_paid' => Money::toDecimalString($b->netPaidCents),
            'pending' => Money::toDecimalString($b->pendingCents),
            'is_settled' => $b->isSettled,
        ];
    }

    /**
     * Lista paginada, filtrable y buscable.
     *
     * Antes devolvia un array pelado con un LIMIT 50 fijo y sin filtros, y la
     * pantalla completaba con una peticion de saldo por pedido. Ahora
     * devuelve un objeto con `items` y `next_cursor`, y el saldo viene
     * dentro de cada fila.
     */
    public static function list(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');

        try {
            $filters = new OrderListFilters(
                statusCategory: Request::queryString('status_category', 20),
                channel: Request::queryString('channel', 20),
                search: Request::queryString('q', 80),
                fromDate: Request::queryDate('from_date'),
                toDate: Request::queryDate('to_date'),
                limit: Request::queryInt(
                    'limit',
                    default: OrderListFilters::LIMIT_DEFAULT,
                    min: 1,
                    max: OrderListFilters::LIMIT_MAX,
                ),
                cursor: Request::queryString('cursor', 200),
                onlyDelivery: Request::queryBool('only_delivery'),
                withNextStatuses: Request::queryBool('with_next_statuses'),
            );
            $page = OrderService::listOrders($ctx->tenantId, Deps::activeBranchId($ctx), $filters);
        } catch (OrderError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return [
            'items' => array_map(
                static fn ($order) => self::orderOut($order) + [
                    'balance' => self::balanceOut($page->balances[$order->id]),
                    'delivery' => isset($page->deliveries[$order->id])
                        ? DeliveryController::infoOut($page->deliveries[$order->id])
                        : null,
                    'next_statuses' => isset($page->nextStatuses[$order->id])
                        ? array_map(self::statusOut(...), $page->nextStatuses[$order->id])
                        : null,
                ],
                $page->orders,
            ),
            'next_cursor' => $page->nextCursor,
        ];
    }

    public static function nextStatuses(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');
        $order = OrderService::getOrder($ctx->tenantId, $params['order_id']);
        if ($order === null) {
            throw new ApiException(404, 'Pedido no encontrado');
        }
        return array_map(self::statusOut(...), OrderStatusService::allowedNextStatuses($ctx->tenantId, $order));
    }

    /**
     * Sin exigir un permiso fijo a proposito: el permiso no lo fija el
     * endpoint sino cada transicion configurada por el tenant
     * (order_status_transitions.required_permission). La maquina de estados
     * lo valida contra los permisos del token.
     */
    public static function changeStatus(array $params): array
    {
        $ctx = Deps::getContext();
        $body = Request::json();

        try {
            $order = OrderStatusService::advanceStatus(
                $ctx->tenantId,
                $params['order_id'],
                Request::uuid($body, 'to_status_id'),
                $ctx->permissions,
                $ctx->userId,
                Request::optionalString($body, 'note'),
            );
        } catch (OrderStatusError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return self::orderOut($order);
    }
}
