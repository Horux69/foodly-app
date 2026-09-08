<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ApiException;
use App\Api\Deps;
use App\Api\Request;
use App\Core\Money;
use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerError;
use App\Services\CustomerService;

/** Equivalente PHP de app/api/v1/customers.py. */
final class CustomerController
{
    private static function customerOut(Customer $c): array
    {
        return [
            'id' => $c->id,
            'phone' => $c->phone,
            'name' => $c->name,
            'email' => $c->email,
            'created_at' => $c->createdAt,
        ];
    }

    public static function search(): array
    {
        $ctx = Deps::require(Deps::getContext(), 'customers.view');
        $customers = CustomerService::search($ctx->tenantId, Request::queryString('q', 100));
        return array_map(self::customerOut(...), $customers);
    }

    public static function detail(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'customers.view');
        try {
            [$customer, $orders, $stats] = CustomerService::detail($ctx->tenantId, $params['customer_id']);
        } catch (CustomerError $e) {
            throw new ApiException(404, $e->getMessage());
        }

        return [
            'customer' => self::customerOut($customer),
            'orders_completed' => $stats['orders'],
            'total_spent' => $stats['spent'],
            'recent_orders' => array_map(static fn (Order $o) => [
                'id' => $o->id,
                'order_number' => $o->orderNumber,
                'channel' => $o->channel,
                'total' => Money::toDecimalString($o->totalCents),
                'created_at' => $o->createdAt,
            ], $orders),
        ];
    }

    public static function update(array $params): array
    {
        $ctx = Deps::require(Deps::getContext(), 'customers.manage');
        $body = Request::json();

        // PATCH parcial: se distingue "no lo mandaron" de "lo mandaron vacio",
        // que es lo que hacia `model_dump(exclude_unset=True)` en Pydantic.
        // El telefono no esta en la lista a proposito: es la identidad.
        $changes = [];
        foreach (['name' => 150, 'email' => 150] as $field => $maxLength) {
            if (array_key_exists($field, $body)) {
                $changes[$field] = Request::optionalString($body, $field);
                if ($changes[$field] !== null && strlen($changes[$field]) > $maxLength) {
                    throw new ApiException(422, "'{$field}' debe tener como maximo {$maxLength} caracteres");
                }
            }
        }

        if ($changes === []) {
            throw new ApiException(400, 'No hay nada que actualizar');
        }

        try {
            $customer = CustomerService::update($ctx->tenantId, $params['customer_id'], $changes);
        } catch (CustomerError $e) {
            throw new ApiException(404, $e->getMessage());
        }

        return self::customerOut($customer);
    }
}
