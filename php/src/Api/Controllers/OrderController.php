<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\JsonResponse;
use App\Api\Request;
use App\Api\RequestContext;
use App\Core\Money;
use App\Models\Order;
use App\Models\OrderStatusRow;
use App\Services\OrderError;
use App\Services\OrderLineInput;
use App\Services\OrderService;
use App\Services\OrderStatusError;
use App\Services\OrderStatusService;

/** Equivalente PHP de app/api/v1/orders.py. */
final class OrderController
{
    public static function orderOut(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->orderNumber,
            'channel' => $order->channel,
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
            ], $order->items),
        ];
    }

    public static function statusOut(OrderStatusRow $s): array
    {
        return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'category' => $s->category, 'color' => $s->color];
    }

    private static function branchOf(RequestContext $ctx): string
    {
        if ($ctx->branchId === null) {
            throw new ApiException(400, 'El usuario no tiene una sucursal asignada');
        }
        return $ctx->branchId;
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

    public static function create(): JsonResponse
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.create');
        $branchId = self::branchOf($ctx);
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
        $branchId = self::branchOf($ctx);
        $body = Request::json();
        [$deliveryFee, $discount, $tip] = self::adjustments($body);

        try {
            $totals = OrderService::previewTotals(
                $ctx->tenantId,
                $branchId,
                self::linesFrom($body),
                $deliveryFee,
                $discount,
                $tip,
            );
        } catch (OrderError $e) {
            throw new ApiException(422, $e->getMessage());
        }

        return [
            'subtotal' => Money::toDecimalString($totals->subtotalCents),
            'tax_total' => Money::toDecimalString($totals->taxTotalCents),
            'delivery_fee' => Money::toDecimalString($totals->deliveryFeeCents),
            'discount' => Money::toDecimalString($totals->discountCents),
            'tip' => Money::toDecimalString($totals->tipCents),
            'total' => Money::toDecimalString($totals->totalCents),
        ];
    }

    public static function get(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');
        $order = OrderService::getOrder($ctx->tenantId, $params['order_id']);
        if ($order === null) {
            throw new ApiException(404, 'Pedido no encontrado');
        }
        return self::orderOut($order);
    }

    public static function list(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'orders.view');
        $orders = OrderService::listOrders($ctx->tenantId, self::branchOf($ctx));
        return array_map(self::orderOut(...), $orders);
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
