<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Customer;
use App\Repositories\CustomerRepository;

/**
 * Clientes (modulo 7).
 *
 * La base de clientes se llena sola: al tomar un pedido con telefono, el
 * cliente queda registrado (ver OrderService::createOrder). Este modulo la
 * vuelve consultable, que es el paso previo a que el agente de WhatsApp
 * reconozca a quien escribe.
 *
 * Equivalente PHP de app/services/customer_service.py.
 */
final class CustomerService
{
    private static function repo(): CustomerRepository
    {
        return new CustomerRepository(Database::app());
    }

    /** @return Customer[] */
    public static function search(string $tenantId, ?string $term = null): array
    {
        return self::repo()->search($tenantId, $term);
    }

    /**
     * Ficha del cliente: sus datos, sus ultimos pedidos y cuanto ha gastado.
     *
     * @return array{0: Customer, 1: \App\Models\Order[], 2: array{orders: int, spent: string}}
     */
    public static function detail(string $tenantId, string $customerId): array
    {
        $repo = self::repo();
        $customer = $repo->get($tenantId, $customerId);
        if ($customer === null) {
            throw new CustomerError('El cliente no existe para este tenant');
        }

        return [
            $customer,
            $repo->ordersOf($tenantId, $customer->id),
            $repo->statsOf($tenantId, $customer->id),
        ];
    }

    /**
     * Se puede corregir el nombre y el correo, no el telefono.
     *
     * El telefono es la identidad del cliente y la llave unica por empresa;
     * cambiarlo convertiria a alguien en otra persona en vez de corregir un
     * dato. Para eso se registra un cliente nuevo.
     *
     * @param array<string, string|null> $changes solo 'name' y/o 'email'
     */
    public static function update(string $tenantId, string $customerId, array $changes): Customer
    {
        $repo = self::repo();
        if ($repo->get($tenantId, $customerId) === null) {
            throw new CustomerError('El cliente no existe para este tenant');
        }

        return $repo->update($tenantId, $customerId, $changes);
    }
}
